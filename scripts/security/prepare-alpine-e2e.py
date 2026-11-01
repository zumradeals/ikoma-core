#!/usr/bin/env python3
"""Prepare a fictitious development/E2E Alpine lab on an isolated Git worktree only."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

if os.environ.get("GITHUB_ACTIONS")!="true":
    raise SystemExit("STOP: must execute in CI only")
root=Path(sys.argv[1]).resolve()
assert (root/"docker/php/Dockerfile").is_file()
assert (root/"docker-compose.yml").is_file()
if (root/".secrets").exists() or (root/"prod-data").exists():
    raise SystemExit("STOP: existing instance or secrets")
def git(*args):
    return subprocess.check_output(("git","-C",str(root),*args),text=True).strip()
revision=git("rev-parse","HEAD")
if revision!=os.environ.get("GITHUB_SHA") or git("status","--porcelain"):
    raise SystemExit("STOP: checkout and clean git state required")
dockerfile=root/"docker/php/Dockerfile"
old=dockerfile.read_text()
start="FROM php:8.5.12-fpm-trixie\n"
end="COPY --from=composer:2 /usr/bin/composer /usr/bin/composer"
if not old.startswith(start) or old.count(end)!=1:
    raise SystemExit("STOP: development image contract changed")
# Le worker Alpine www-data (82:82) doit lire les volumes de test groupés 33 comme Debian.
# Pas de chmod global ni exécution du worker en root.
alpine=r"""FROM php:8.5.12-fpm-alpine3.24
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache libpq oniguruma libxml2 libzip libjpeg-turbo libpng unzip git; \
    apk add --no-cache --virtual .ikoma-compile $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev libzip-dev libjpeg-turbo-dev libpng-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    apk del .ikoma-compile; \
    env_group="$(awk -F: '$3 == 33 {print $1; exit}' /etc/group)"; \
    if [ -z "$env_group" ]; then addgroup -S -g 33 ikoma-fpm-files; env_group=ikoma-fpm-files; fi; \
    addgroup www-data "$env_group"; \
    id -G www-data | grep -Eq '(^| )33( |$)'
"""
dockerfile.write_text(alpine+old[old.index(end):])
compose=root/"docker-compose.yml"
text=compose.read_text()
old="image: postgres:18.6-trixie"
if text.count(old)!=1:raise SystemExit("STOP: PostgreSQL dev base contract changed")
compose.write_text(text.replace(old,"image: ikoma-qualification-postgres:18.6-alpine3.24"))
# Première passe de diagnostic : arrêt au premier échec réel, sans 40 minutes de retries supplémentaires.
# Le passage complet sera relancé après correction ; modification limitée au worktree éphémère.
playwright=root/"playwright.config.ts"
config=playwright.read_text()
if config.count("workers: 1,")!=1:
    raise SystemExit("STOP: Playwright configuration contract changed")
playwright.write_text(config.replace("workers: 1,","workers: 1, maxFailures: 1,",1))
git("add","docker/php/Dockerfile","docker-compose.yml","playwright.config.ts")
subprocess.check_call(("git","-C",str(root),"-c","user.name=IKOMA Qualification",
    "-c","user.email=ci@invalid.example","commit","-m","Temporary Alpine E2E fixture - not deliverable"),
    stdout=subprocess.DEVNULL)
manifest={"kind":"isolated-dev-e2e-alpine-not-a-release","pr_checkout":revision,
          "local_fixture_commit":git("rev-parse","HEAD"),
          "dev_dockerfile_sha256":hashlib.sha256(dockerfile.read_bytes()).hexdigest(),
          "dev_compose_sha256":hashlib.sha256(compose.read_bytes()).hexdigest(),
          "customer_data":False}
out=Path(os.environ["RUNNER_TEMP"])/"adoption-e2e"
out.mkdir(exist_ok=True)
(out/"provenance.json").write_text(json.dumps(manifest,indent=2)+"\n")
