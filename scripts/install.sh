#!/bin/sh
set -eu
docker compose build php
docker compose --env-file /dev/null run --rm --no-deps -e IKOMA_LOCAL_UID="$(id -u)" php php scripts/prepare-environment.php
