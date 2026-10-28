#!/bin/bash
# Construit les images de production, étiquetées par version. Usage : bash deploy/prod/build.sh [version]
. "$(dirname "$0")/lib.sh"
VERSION="${1:-$(git -C "$ROOT" rev-parse --short=12 HEAD)}"
mkdir -p "$DATA"
[ -f "$DATA/env" ] || printf 'IKOMA_VERSION=%s\nIKOMA_DOMAIN=localhost\n' "$VERSION" > "$DATA/env"
say "Construction des images ikoma-php:$VERSION et ikoma-nginx:$VERSION"
IKOMA_VERSION="$VERSION" dc build --pull
say "Terminé"
docker image ls --format '{{.Repository}}:{{.Tag}}  {{.ID}}' | grep -E "^ikoma-(php|nginx):$VERSION" || true
