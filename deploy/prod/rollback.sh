#!/bin/bash
# Retour arrière (lot L12) : redémarre la version précédente (ses images restent sur le serveur).
# Règle du projet : les migrations sont additives (ajout de colonnes, de tables, de réglages), donc le code précédent fonctionne sur la base migrée.
# Si une migration a été destructive ou si les données sont abîmées, restaurez plutôt la sauvegarde : bash deploy/prod/restore.sh --remplacer-la-production.
. "$(dirname "$0")/lib.sh"
PREVIOUS="$(cat "$DATA/previous-version" 2>/dev/null || true)"
[ -n "$PREVIOUS" ] || fail "aucune version précédente enregistrée : rien à restaurer."
docker image inspect "ikoma-php:$PREVIOUS" >/dev/null 2>&1 || fail "l'image ikoma-php:$PREVIOUS n'est plus sur ce serveur : reconstruisez-la (git checkout $PREVIOUS && bash deploy/prod/build.sh $PREVIOUS)."
say "Retour à la version $PREVIOUS"
sed -i "s/^IKOMA_VERSION=.*/IKOMA_VERSION=$PREVIOUS/" "$DATA/env"
dc up -d --no-build
sleep 5
bash "$ROOT/deploy/prod/smoke.sh"
