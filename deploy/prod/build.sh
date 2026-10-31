#!/bin/bash
# Construit les images finales avec une identité de source vérifiable, sans changer les versions.
. "$(dirname "$0")/lib.sh"
VERSION="${1:-$(git -C "$ROOT" rev-parse --short=12 HEAD)}"
BUILD_REVISION="$(git -C "$ROOT" rev-parse HEAD)"
git -C "$ROOT" diff --quiet HEAD -- || fail "construction refusée : des fichiers suivis diffèrent du commit annoncé."
BUILD_SOURCE_PATHS=(app bootstrap config database routes lang resources public scripts docker composer.json composer.lock package.json package-lock.json vite.config.ts tsconfig.json .dockerignore)
[ -z "$(git -C "$ROOT" ls-files --others --exclude-standard -- "${BUILD_SOURCE_PATHS[@]}")" ] || fail "construction refusée : des sources non suivies par Git seraient incorporées."
mkdir -p "$DATA"
[ -f "$DATA/env" ] || printf 'IKOMA_VERSION=%s\nIKOMA_DOMAIN=localhost\n' "$VERSION" > "$DATA/env"
say "Construction des images ikoma-php:$VERSION et ikoma-nginx:$VERSION (source $BUILD_REVISION)"
IKOMA_VERSION="$VERSION" dc build --pull --build-arg "IKOMA_REVISION=$BUILD_REVISION" --build-arg "IKOMA_BUILD_VERSION=$VERSION"
[ "$BUILD_REVISION" = "$(git -C "$ROOT" rev-parse HEAD)" ] || fail "le commit source a changé pendant la construction."
git -C "$ROOT" diff --quiet HEAD -- || fail "les fichiers suivis ont changé pendant la construction."
say "Terminé"
docker image ls --format '{{.Repository}}:{{.Tag}}  {{.ID}}' | grep -E "^ikoma-(php|nginx):$VERSION" || true
