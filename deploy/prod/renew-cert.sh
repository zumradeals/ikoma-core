#!/bin/bash
# Certificat Let's Encrypt : installation et renouvellement (lot L12).
#   bash deploy/prod/renew-cert.sh                      renouvelle si nécessaire (à planifier chaque semaine : voir docs/DEPLOIEMENT.md)
#   bash deploy/prod/renew-cert.sh --installer DOMAINE  copie le certificat déjà obtenu et recharge Nginx
# Réseau fermé : remplacez simplement prod-data/tls/fullchain.pem et privkey.pem par ceux de l'autorité interne, puis : bash deploy/prod/renew-cert.sh --recharger
. "$(dirname "$0")/lib.sh"
install_cert() { # domaine
  local live="/etc/letsencrypt/live/$1"
  [ -r "$live/fullchain.pem" ] || fail "certificat introuvable dans $live (lancez d'abord certbot)."
  as_root cp -f "$live/fullchain.pem" "$DATA/tls/fullchain.pem"
  as_root cp -f "$live/privkey.pem" "$DATA/tls/privkey.pem"
  as_root chown 101:101 "$DATA/tls/fullchain.pem" "$DATA/tls/privkey.pem"
  as_root chmod 0444 "$DATA/tls/fullchain.pem"; as_root chmod 0400 "$DATA/tls/privkey.pem"
  dc exec -T nginx nginx -s reload
  echo "Certificat installé et Nginx rechargé."
}
case "${1:-}" in
  --installer) install_cert "${2:?domaine manquant}" ;;
  --recharger) dc exec -T nginx nginx -s reload ;;
  "") DOMAIN="$(env_value IKOMA_DOMAIN)"; command -v certbot >/dev/null || fail "certbot est introuvable."
      certbot renew --webroot -w "$DATA/acme" --deploy-hook "bash $ROOT/deploy/prod/renew-cert.sh --installer $DOMAIN" ;;
  *) fail "option inconnue : $1" ;;
esac
