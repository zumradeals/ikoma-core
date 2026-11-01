#!/usr/bin/env python3
"""Generate an isolated Git worktree candidate. Never edit production configuration in the checkout."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

if os.environ.get("GITHUB_ACTIONS") != "true":
    raise SystemExit("Qualification refused outside GitHub Actions.")

def run(*args):
    return subprocess.check_output(args, text=True).strip()

def single(text, old, new, name):
    if text.count(old) != 1:
        raise RuntimeError("Contract changed, refuse transformation: " + name)
    return text.replace(old, new)

root = Path(sys.argv[1]).resolve()
if not root.is_dir() or not (root / "docker/prod/Dockerfile").exists():
    raise SystemExit("Isolated worktree missing.")
if (root / "prod-data").exists():
    raise SystemExit("Existing instance data detected in isolated workspace.")

original = run("git", "-C", str(root), "rev-parse", "HEAD")
if original != os.environ.get("GITHUB_SHA"):
    raise SystemExit("Checkout does not match CI source revision.")
if run("git", "-C", str(root), "status", "--porcelain"):
    raise SystemExit("Worktree must start clean.")

file = root / "docker/prod/Dockerfile"
content = file.read_text()
original_debian_base = "FROM php:8.5.12-fpm-trixie AS base"
current_alpine_base = "FROM php:8.5.12-fpm-alpine3.24 AS base"
if content.count(original_debian_base) == 1:
    start = content.index(original_debian_base)
    end = content.index("# 3) Dépendances PHP de production", start)
    base_was_debian = True
elif content.count(current_alpine_base) == 1:
    start = content.index(current_alpine_base)
    end = content.index("# 3) Dépendances PHP de production", start)
    base_was_debian = False
else:
    raise SystemExit("STOP: unsupported production PHP base")
new_base = r"""FROM php:8.5.12-fpm-alpine3.24 AS base
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache libpq oniguruma libxml2 libzip libjpeg-turbo libpng; \
    apk add --no-cache --virtual .ikoma-compile $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev libzip-dev libjpeg-turbo-dev libpng-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    apk del .ikoma-compile

"""
if base_was_debian:
    content = content[:start] + new_base + content[end:]
    content = single(
        content,
        "RUN useradd --uid 10001 --user-group --no-create-home --shell /usr/sbin/nologin ikoma",
        "RUN addgroup -S -g 10001 ikoma && adduser -S -D -H -u 10001 -G ikoma -s /sbin/nologin ikoma",
        "user creation",
    )
else:
    expected_user = "RUN addgroup -S -g 10001 ikoma && adduser -S -D -H -u 10001 -G ikoma -s /sbin/nologin ikoma"
    if content.count(expected_user) != 1 or not ("apk del .ikoma-compile" in content[start:end]):
        raise SystemExit("STOP: PHP Alpine runtime or non-root identity contract changed")
file.write_text(content)

compose = root / "docker-compose.prod.yml"
compose.write_text(single(
    compose.read_text(),
    "image: postgres:18.6-trixie",
    "image: ikoma-qualification-postgres:18.6-alpine3.24",
    "database official base",
))

run("git", "-C", str(root), "add", "docker/prod/Dockerfile", "docker-compose.prod.yml")
run("git", "-C", str(root), "-c", "user.name=IKOMA CI Qualification",
    "-c", "user.email=qualification@invalid.example", "commit", "-m",
    "CI ephemeral integrated Alpine candidate - NEVER DEPLOY")
candidate = run("git", "-C", str(root), "rev-parse", "HEAD")

out = Path(os.environ["RUNNER_TEMP"]) / "critical-integrated"
out.mkdir(parents=True, exist_ok=True)
manifest = {
    "kind": "ephemeral-ci-candidate-not-a-release",
    "source_pr_checkout_sha": original,
    "local_qualification_commit": candidate,
    "php_dockerfile_sha256": hashlib.sha256(file.read_bytes()).hexdigest(),
    "compose_sha256": hashlib.sha256(compose.read_bytes()).hexdigest(),
    "php_version_line": "php:8.5.12-fpm-alpine3.24",
    "postgres_image_line": "ikoma-qualification-postgres:18.6-alpine3.24",
    "no_client_instance": True,
}
(out / "provenance.json").write_text(json.dumps(manifest, indent=2) + "\n")
print("Isolated worktree candidate created:", candidate)
