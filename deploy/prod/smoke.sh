#!/bin/bash
# Vérification après déploiement (lot L12) : « ça marche, et c'est bien fermé ». Code de sortie non nul au moindre échec.
#   bash deploy/prod/smoke.sh [--apres-installation]
. "$(dirname "$0")/lib.sh"
AFTER=0; [ "${1:-}" != "--apres-installation" ] || AFTER=1
DOMAIN="$(env_value IKOMA_DOMAIN localhost)"; HTTPS_PORT="$(env_value IKOMA_HTTPS_PORT 443)"; HTTP_PORT="$(env_value IKOMA_HTTP_PORT 80)"
FAILED=0

check() { # libellé, fonction ou commande...
  local label="$1"; shift
  if "$@" >/dev/null 2>&1; then echo "  ✓ $label"; else echo "  ✗ $label"; FAILED=1; fi
}
# Pas de « grep -q » en fin de tube : avec pipefail, il ferme le tube trop tôt et fait échouer à tort la commande précédente.
tls_get() { curl -ks --max-time 10 --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" "https://$DOMAIN:$HTTPS_PORT$1"; }
tls_headers() { curl -ks --max-time 10 --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" -D - -o /dev/null "https://$DOMAIN:$HTTPS_PORT$1"; }
tls_code() { curl -ks --max-time 10 -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" "https://$DOMAIN:$HTTPS_PORT$1"; }

health_ok() { [[ "$(tls_get /sante)" == *'"base":"ok"'* ]]; }
redirects_to_https() { local r; r="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 10 --resolve "$DOMAIN:$HTTP_PORT:127.0.0.1" "http://$DOMAIN:$HTTP_PORT/login")"; [[ "$r" == "301 https://$DOMAIN"* ]]; }
login_page() { [[ "$(tls_get /login)" == *IKOMA* ]]; }
manifest() { [[ "$(tls_get /build/manifest.webmanifest)" == *IKOMA* ]]; }
header_present() { [[ "$HEADERS" =~ (^|$'\n')"$1": ]] || [[ "${HEADERS,,}" == *$'\n'"${1,,}:"* ]] || [[ "${HEADERS,,}" == "${1,,}:"* ]]; }
no_version_headers() { ! grep -qiE '^(x-powered-by|server): .*[0-9]' <<<"$HEADERS"; }
# Ports réellement publiés sur la machine par le conteneur de la base (liste vide = aucun).
db_not_published() {
  local id published
  id="$(dc ps -q db)"; [ -n "$id" ] || return 1
  published="$(docker inspect --format '{{range $port, $bindings := .NetworkSettings.Ports}}{{if $bindings}}{{$port}} {{end}}{{end}}' "$id")"
  [ -z "${published// /}" ]
}
not_root() { [ "$(dc exec -T "$1" id -u)" != 0 ]; }
installer_closed() { [ "$(tls_code /installation)" = 404 ]; }

say "Vérifications"
check "la page de santé répond et la base est joignable" health_ok
check "HTTP redirige vers HTTPS" redirects_to_https
check "la page de connexion s'affiche" login_page
HEADERS="$(tls_headers /login || true)"
check "politique de sécurité du contenu présente" header_present Content-Security-Policy
check "HSTS présent" header_present Strict-Transport-Security
check "X-Content-Type-Options présent" header_present X-Content-Type-Options
check "aucune version de serveur ni de PHP dans les en-têtes" no_version_headers
check "l'interface construite est servie" manifest
check "la base n'est pas joignable depuis l'extérieur (aucun port publié)" db_not_published
check "le conteneur php ne tourne pas en root" not_root php
check "le conteneur nginx ne tourne pas en root" not_root nginx
check "le conteneur scheduler ne tourne pas en root" not_root scheduler
if [ "$AFTER" = 1 ]; then check "l'assistant d'installation est fermé (404)" installer_closed; fi
if [ "$FAILED" = 0 ]; then say "Tout est conforme."; else fail "au moins une vérification a échoué : ne mettez pas en service."; fi
