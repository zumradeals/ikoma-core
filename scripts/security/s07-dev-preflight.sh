#!/bin/sh
# Development only: do not silently replace an existing Debian db with empty Alpine.
set -eu
command -v docker >/dev/null 2>&1 || { echo 'STOP S0.7 dev: Docker missing' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo 'STOP S0.7 dev: Docker not responding' >&2; exit 1; }
project="$(docker compose config --format json | python3 -c 'import sys,json;print(json.load(sys.stdin)["name"])')" || {
  echo 'STOP S0.7 dev: Compose project name unresolved' >&2; exit 1;
}
case "$project" in ''|*[!a-zA-Z0-9_-]*) echo 'STOP S0.7 dev: invalid project name' >&2; exit 1 ;; esac
if docker volume inspect "${project}_postgres" >/dev/null 2>&1; then
  echo "STOP S0.7 dev: legacy volume ${project}_postgres exists. Backup/restore explicitly before using Alpine; never discard it." >&2
  exit 1
fi
echo 'S0.7 dev preflight PASS: no legacy PostgreSQL named volume'
