#!/bin/bash
# Mise à jour (lot L12) : sauvegarde vérifiée AVANT, nouvelle version construite, migrations, redémarrage court, vérification, retour arrière automatique en cas d'échec.
# Une tablette hors ligne continue de travailler pendant le redémarrage (quelques secondes) et synchronise ensuite.
#   bash deploy/prod/update.sh <étiquette-ou-branche-git>
. "$(dirname "$0")/lib.sh"
REF="${1:?indiquez la version à installer : étiquette ou branche Git}"
PREVIOUS="$(env_value IKOMA_VERSION)"
[ -z "$(git -C "$ROOT" status --porcelain)" ] || fail "le dossier $ROOT contient des modifications locales : elles seraient perdues. Annulez-les d'abord."

say "1/6 Sauvegarde préalable (obligatoire : pas de migration sans sauvegarde vérifiée)"
bash "$ROOT/deploy/prod/backup.sh" || fail "la sauvegarde a échoué : la mise à jour est abandonnée, rien n'a changé."

say "2/6 Récupération de la version $REF"
git -C "$ROOT" fetch --tags --quiet origin
git -C "$ROOT" checkout --quiet "$REF"
if git -C "$ROOT" symbolic-ref -q HEAD >/dev/null; then git -C "$ROOT" merge --ff-only --quiet "origin/$REF"; fi
VERSION="$(git -C "$ROOT" rev-parse --short=12 HEAD)"
echo "$PREVIOUS" > "$DATA/previous-version"
echo "Version précédente : $PREVIOUS ; nouvelle version : $VERSION"

say "3/6 Construction"
bash "$ROOT/deploy/prod/build.sh" "$VERSION"
sed -i "s/^IKOMA_VERSION=.*/IKOMA_VERSION=$VERSION/" "$DATA/env"

say "4/6 Migrations et redémarrage"
dc up -d

say "5/6 Vérification"
sleep 5
if bash "$ROOT/deploy/prod/smoke.sh"; then
  say "6/6 Mise à jour réussie : $PREVIOUS → $VERSION"
else
  echo "La vérification a échoué : retour arrière automatique vers $PREVIOUS."
  bash "$ROOT/deploy/prod/rollback.sh"
  fail "mise à jour annulée."
fi
