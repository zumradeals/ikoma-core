#!/bin/sh
# S0.7 fail-closed production preflight. No databases/volumes are modified.
set -eu
mode="${1:-}"
env_file="${2:-}"
case "$mode" in fresh|update|rollback) ;; *) echo 'STOP S0.7: unknown preflight mode' >&2; exit 1 ;; esac
test -n "$env_file" || { echo 'STOP S0.7: missing production env file location' >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo 'STOP S0.7: Docker unavailable' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo 'STOP S0.7: cannot inspect Docker volumes' >&2; exit 1; }

# Old Compose: name=ikoma, volumes: postgres. Never allow an ordinary
# install/update/rollback to silently create an empty Alpine database while
# user data remain in the old Debian Docker volume.
if docker volume inspect ikoma_postgres >/dev/null 2>&1; then
  echo 'STOP S0.7: existing Debian volume ikoma_postgres. No automatic adoption; preserve old cluster, require separately approved logical migration.' >&2
  exit 1
fi

policy='IKOMA_S07_GUARD=FRESH_EMPTY_INSTALLATION_ONLY'
if [ -e "$env_file" ]; then
  test -f "$env_file" && grep -qx "$policy" "$env_file" || {
    echo 'STOP S0.7: existing installation lacks exact fresh-volume policy; do not overwrite it.' >&2
    exit 1
  }
elif [ "$mode" != fresh ]; then
  echo 'STOP S0.7: update/rollback requires a previously approved Alpine installation.' >&2
  exit 1
fi

# Re-using an already created S0.7 volume requires the pre-existing env policy.
if docker volume inspect ikoma_postgres_s07_alpine >/dev/null 2>&1; then
  test -f "$env_file" && grep -qx "$policy" "$env_file" || {
    echo 'STOP S0.7: Alpine volume exists without documented installation policy.' >&2
    exit 1
  }
fi

printf 'S0.7 preflight PASS for %s (no legacy volume, explicit fresh policy)\n' "$mode"
