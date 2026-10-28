#!/bin/bash
# Recette automatique de la production (lot L12), lancée par la CI APRÈS « install.sh » : assistant d'initialisation, vérifications,
# refus des données de démonstration, sauvegarde chiffrée puis restauration, retour arrière. Aucune donnée réelle.
#   bash deploy/prod/ci-recette.sh <clé-privée-de-sauvegarde.asc>
. "$(dirname "$0")/lib.sh"
PRIVATE_KEY="${1:?clé privée de sauvegarde (fichier)}"
DOMAIN="$(env_value IKOMA_DOMAIN)"; PORT="$(env_value IKOMA_HTTPS_PORT 443)"
BASE="https://$DOMAIN:$PORT"
CURL=(curl -ks --max-time 20 --resolve "$DOMAIN:$PORT:127.0.0.1")
PASSWORD="Mot-de-passe-Fictif-$(openssl rand -hex 6)-A1!"

say "Avant installation : l'assistant est disponible et le serveur est sain"
[ "$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/installation")" = 200 ] || fail "l'assistant d'installation devrait répondre (200)."
bash "$ROOT/deploy/prod/smoke.sh"

say "Assistant d'initialisation"
JAR="$(mktemp)"
"${CURL[@]}" -c "$JAR" -o /dev/null "$BASE/installation"
XSRF="$(awk '$6=="XSRF-TOKEN"{print $7}' "$JAR" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')"
TOKEN="$(as_root cat "$DATA/secrets/install_token")"
CODE="$("${CURL[@]}" -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/installation" -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: text/html' \
  --data-urlencode "token=$TOKEN" --data-urlencode 'name=Administrateur fictif' --data-urlencode 'email=admin-recette@exemple.test' \
  --data-urlencode "password=$PASSWORD" --data-urlencode "password_confirmation=$PASSWORD" \
  --data-urlencode 'event_name=Événement fictif de recette' --data-urlencode 'site=Site fictif' \
  --data-urlencode 'starts_at=2027-03-01 08:00' --data-urlencode 'ends_at=2027-03-01 18:00')"
[ "$CODE" = 302 ] || fail "l'assistant devait rediriger (302), reçu $CODE."

say "Après installation : fermé pour toujours, serveur conforme"
[ "$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$BASE/installation")" = 404 ] || fail "l'assistant doit être introuvable (404) après l'installation."
CODE="$("${CURL[@]}" -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/installation" -H "X-XSRF-TOKEN: $XSRF" -H 'Accept: text/html' --data-urlencode "token=$TOKEN")"
[ "$CODE" = 404 ] || fail "un second POST sur l'assistant doit être refusé (404), reçu $CODE."
bash "$ROOT/deploy/prod/smoke.sh" --apres-installation

say "Les données de démonstration sont refusées en production"
SEED_OUTPUT="$(dc exec -T php ikoma-entrypoint php artisan db:seed --force 2>&1)" && fail "le seeder de démonstration s'est exécuté en production."
# Il doit être refusé pour la bonne raison (le garde de production), pas parce que l'application ne démarre pas.
[[ "$SEED_OUTPUT" == *'jamais créées en production'* ]] || fail "refus pour une autre raison que le garde de production : $SEED_OUTPUT"
echo "  ✓ refusé par le garde de production"

say "Sauvegarde chiffrée"
bash "$ROOT/deploy/prod/backup.sh"
DB_FILE="$(ls -1t "$DATA"/backups/ikoma-base-*.dump.gpg | head -n1)"; FILES_FILE="$(ls -1t "$DATA"/backups/ikoma-fichiers-*.tar.gpg | head -n1)"
if grep -q 'IKOMA' "$DB_FILE" 2>/dev/null; then fail "la sauvegarde n'est pas chiffrée."; fi
HEALTH="$("${CURL[@]}" "$BASE/sante")"
[[ "$HEALTH" == *'"sauvegarde":{"etat":"ok"'* ]] || fail "la page de santé doit indiquer une sauvegarde réussie : $HEALTH"
echo "  ✓ sauvegarde chiffrée, enregistrée et visible sur /sante"

say "Restauration dans une base vierge, audit:verify et effectifs identiques"
bash "$ROOT/deploy/prod/restore.sh" --base "$DB_FILE" --fichiers "$FILES_FILE" --cle-privee "$PRIVATE_KEY"

say "Mise à jour puis retour arrière"
ORIGINAL="$(env_value IKOMA_VERSION)"
bash "$ROOT/deploy/prod/build.sh" "${ORIGINAL}-bis" >/dev/null
echo "$ORIGINAL" > "$DATA/previous-version"
sed -i "s/^IKOMA_VERSION=.*/IKOMA_VERSION=${ORIGINAL}-bis/" "$DATA/env"
dc up -d
sleep 8
bash "$ROOT/deploy/prod/smoke.sh" --apres-installation
bash "$ROOT/deploy/prod/rollback.sh"
[ "$(env_value IKOMA_VERSION)" = "$ORIGINAL" ] || fail "le retour arrière n'a pas rétabli la version $ORIGINAL."

say "Recette de production réussie."
