#!/bin/bash
# Installation guidée d'IKOMA Accueil en PRODUCTION (lot L12). Idempotent : relançable sans rien casser ni changer les secrets déjà créés.
# Usage :
#   bash deploy/prod/install.sh --domaine ikoma.exemple.org --tls letsencrypt --courriel admin@exemple.org --cle-sauvegarde cle-publique.asc
#   bash deploy/prod/install.sh --domaine ikoma.interne --tls interne --cle-sauvegarde cle-publique.asc          (réseau fermé, certificat interne auto-signé)
#   bash deploy/prod/install.sh --domaine ikoma.interne --tls fourni --certificat fullchain.pem --cle-privee privkey.pem --cle-sauvegarde cle-publique.asc
# Options : --version V (défaut : version Git courante), --sans-planification (ne programme pas la sauvegarde quotidienne),
#           --ports HTTP:HTTPS (défaut 80:443). Aucune donnée de démonstration n'est jamais créée.
. "$(dirname "$0")/lib.sh"

DOMAIN=""; TLS=""; EMAIL=""; CERT=""; PRIVKEY=""; BACKUP_KEY=""; VERSION=""; HTTP_PORT=80; HTTPS_PORT=443; SCHEDULE=1
while [ $# -gt 0 ]; do
  case "$1" in
    --domaine) DOMAIN="$2"; shift 2 ;;
    --tls) TLS="$2"; shift 2 ;;
    --courriel) EMAIL="$2"; shift 2 ;;
    --certificat) CERT="$2"; shift 2 ;;
    --cle-privee) PRIVKEY="$2"; shift 2 ;;
    --cle-sauvegarde) BACKUP_KEY="$2"; shift 2 ;;
    --version) VERSION="$2"; shift 2 ;;
    --ports) HTTP_PORT="${2%%:*}"; HTTPS_PORT="${2##*:}"; shift 2 ;;
    --sans-planification) SCHEDULE=0; shift ;;
    *) fail "option inconnue : $1 (voir l'en-tête de ce script)" ;;
  esac
done
[ -n "$DOMAIN" ] || fail "indiquez le nom du serveur : --domaine ikoma.exemple.org"
case "$TLS" in letsencrypt|interne|fourni|autosigne) ;; *) fail "indiquez le mode du certificat : --tls letsencrypt | interne | fourni" ;; esac
[ "$TLS" != letsencrypt ] || [ -n "$EMAIL" ] || fail "Let's Encrypt demande un courriel : --courriel admin@exemple.org"
[ "$TLS" != fourni ] || { [ -r "$CERT" ] && [ -r "$PRIVKEY" ]; } || fail "--tls fourni demande --certificat et --cle-privee (fichiers lisibles)"
[ -n "$BACKUP_KEY" ] && [ -r "$BACKUP_KEY" ] || fail "indiquez la clé PUBLIQUE de sauvegarde : --cle-sauvegarde cle-publique.asc (la clé privée reste hors du serveur : voir docs/DEPLOIEMENT.md)"
VERSION="${VERSION:-$(git -C "$ROOT" rev-parse --short=12 HEAD)}"

say "1/9 Prérequis"
command -v docker >/dev/null || fail "Docker n'est pas installé (https://docs.docker.com/engine/install/)."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 est introuvable."
docker info >/dev/null 2>&1 || fail "Docker ne répond pas : démarrez-le, ou ajoutez votre utilisateur au groupe docker."
for tool in openssl gpg curl; do command -v "$tool" >/dev/null || fail "l'outil « $tool » est requis (apt install $tool)."; done
FREE_KB="$(df -Pk "$ROOT" | awk 'NR==2{print $4}')"
[ "$FREE_KB" -gt 5000000 ] || fail "moins de 5 Go libres dans $ROOT : libérez de la place."
for port in "$HTTP_PORT" "$HTTPS_PORT"; do
  LISTENING="$(ss -ltnH "sport = :$port" 2>/dev/null || true)"; RUNNING="$(dc ps --status running 2>/dev/null || true)"
  if [ -n "$LISTENING" ] && [[ "$RUNNING" != *nginx* ]]; then fail "le port $port est déjà utilisé sur ce serveur."; fi
done
gpg --show-keys "$BACKUP_KEY" >/dev/null 2>&1 || fail "$BACKUP_KEY n'est pas une clé GPG publique valable."
echo "Prérequis réunis."

say "2/9 Dossier des réglages et des secrets ($DATA)"
umask 077
mkdir -p "$DATA/secrets" "$DATA/tls" "$DATA/acme" "$DATA/backups"
chmod 0700 "$DATA" "$DATA/secrets"
chmod 0755 "$DATA/tls" "$DATA/acme"
printf 'IKOMA_VERSION=%s\nIKOMA_DOMAIN=%s\nIKOMA_HTTP_PORT=%s\nIKOMA_HTTPS_PORT=%s\n' "$VERSION" "$DOMAIN" "$HTTP_PORT" "$HTTPS_PORT" > "$DATA/env"

say "3/9 Secrets (générés ici, jamais dans Git ni dans les images)"
generate() { # nom, contenu
  if [ -s "$DATA/secrets/$1" ]; then echo "  $1 : déjà présent (conservé)"; else printf '%s' "$2" > "$DATA/secrets/$1"; echo "  $1 : créé"; fi
}
generate app_key "base64:$(openssl rand -base64 32)"
generate db_owner "$(openssl rand -hex 24)"
generate db_app "$(openssl rand -hex 24)"
generate install_token "$(openssl rand -hex 24)"
# Les conteneurs PHP tournent avec l'utilisateur 10001 : lui seul peut lire ces fichiers (la base, qui démarre en root, les lit aussi).
as_root chown 10001:10001 "$DATA"/secrets/*
as_root chmod 0400 "$DATA"/secrets/*

say "4/9 Configuration de la porte d'entrée"
sed "s/@@DOMAIN@@/$DOMAIN/g" "$ROOT/docker/prod/nginx.conf.template" > "$DATA/nginx.conf"
chmod 0644 "$DATA/nginx.conf"

say "5/9 Certificat HTTPS (mode : $TLS)"
if [ "$TLS" = fourni ]; then
  as_root cp -f "$CERT" "$DATA/tls/fullchain.pem"; as_root cp -f "$PRIVKEY" "$DATA/tls/privkey.pem"
elif [ ! -s "$DATA/tls/fullchain.pem" ]; then
  # Certificat auto-signé : réseau fermé (à remplacer par celui de l'autorité interne du client) ou certificat provisoire avant Let's Encrypt.
  openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" -keyout "$DATA/tls/privkey.pem" -out "$DATA/tls/fullchain.pem" 2>/dev/null
fi
as_root chown 101:101 "$DATA/tls/privkey.pem" "$DATA/tls/fullchain.pem"
as_root chmod 0400 "$DATA/tls/privkey.pem"; as_root chmod 0444 "$DATA/tls/fullchain.pem"

say "6/9 Clé publique de sauvegarde et réglages"
cp "$BACKUP_KEY" "$DATA/backup-public.asc"
[ -f "$DATA/backup.conf" ] || printf '# Réglages de la sauvegarde (voir docs/DEPLOIEMENT.md)\nBACKUP_RETENTION_DAYS=14\n# Copie vers un emplacement externe : commande recevant le chemin du fichier en argument, par exemple\n# BACKUP_COPY_COMMAND='"'"'rsync -a --chmod=F600 "$1" sauvegarde@serveur-distant:/sauvegardes/ikoma/'"'"'\nBACKUP_COPY_COMMAND=\n' > "$DATA/backup.conf"

say "7/9 Construction des images (plusieurs minutes la première fois)"
bash "$ROOT/deploy/prod/build.sh" "$VERSION"

say "8/9 Démarrage"
dc up -d
echo -n "Attente de l'application"
for _ in $(seq 1 90); do
  HEALTH="$(curl -ks --max-time 3 --resolve "$DOMAIN:$HTTPS_PORT:127.0.0.1" "https://$DOMAIN:$HTTPS_PORT/sante" || true)"
  if [[ "$HEALTH" == *'"base":"ok"'* ]]; then echo " : prête."; READY=1; break; fi
  echo -n "."; sleep 2
done
[ "${READY:-0}" = 1 ] || { dc ps; fail "l'application ne répond pas. Voir : docker compose --project-directory $ROOT --env-file $DATA/env -f $ROOT/docker-compose.prod.yml logs"; }

if [ "$TLS" = letsencrypt ]; then
  say "Certificat Let's Encrypt"
  command -v certbot >/dev/null || fail "installez certbot (apt install certbot) puis relancez ce script."
  certbot certonly --webroot -w "$DATA/acme" -d "$DOMAIN" -m "$EMAIL" --agree-tos --no-eff-email -n
  bash "$ROOT/deploy/prod/renew-cert.sh" --installer "$DOMAIN"
fi

say "9/9 Sauvegarde quotidienne"
if [ "$SCHEDULE" = 1 ] && [ "$(id -u)" -eq 0 ] && [ -d /etc/cron.d ]; then
  printf '# IKOMA Accueil : sauvegarde chiffrée quotidienne\n17 2 * * * root bash %s/deploy/prod/backup.sh >> /var/log/ikoma-sauvegarde.log 2>&1\n' "$ROOT" > /etc/cron.d/ikoma-sauvegarde
  printf '# IKOMA ACCESS : demandes de maintenance autorisées depuis l’interface\n* * * * * root bash %s/deploy/prod/maintenance.sh >> /var/log/ikoma-maintenance.log 2>&1\n' "$ROOT" > /etc/cron.d/ikoma-maintenance
  chmod 0644 /etc/cron.d/ikoma-maintenance
  chmod 0644 /etc/cron.d/ikoma-sauvegarde; echo "Programmée tous les jours à 02 h 17 (/etc/cron.d/ikoma-sauvegarde)."
else
  echo "Non programmée automatiquement. Ajoutez à la planification du serveur : 17 2 * * * bash $ROOT/deploy/prod/backup.sh"
fi

TOKEN="$(as_root cat "$DATA/secrets/install_token")"
cat <<MSG

==================== INSTALLATION DES SERVICES TERMINÉE ====================
1. Ouvrez : https://$DOMAIN$([ "$HTTPS_PORT" = 443 ] || printf ':%s' "$HTTPS_PORT")/installation
2. Jeton d'installation (à usage unique, à saisir dans l'assistant) :

     $TOKEN

3. L'assistant crée le premier administrateur et le premier événement, puis se ferme pour toujours.
4. Première connexion : l'application vous demande de configurer la double authentification.
5. Vérification : bash $ROOT/deploy/prod/smoke.sh --apres-installation
============================================================================
MSG
