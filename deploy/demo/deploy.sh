#!/bin/sh
# Déploiement (et mise à jour) de la démonstration IKOMA Accueil sur le serveur Contabo : https://ikoma.dgafrique.com
# À lancer en root sur le serveur : sh deploy.sh   (voir docs/DEPLOIEMENT_DEMO.md)
# DONNÉES FICTIVES UNIQUEMENT. Ce n'est pas la production (lot L12).
set -eu

DOMAIN="${DOMAIN:-ikoma.dgafrique.com}"
REPO="${REPO:-https://github.com/zumradeals/ikoma-core.git}"
BRANCH="${BRANCH:-main}"
DIR="${DIR:-/opt/ikoma}"
COMPOSE="docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml"

say() { printf '\n==> %s\n' "$1"; }
fail() { printf '\nERREUR : %s\n' "$1" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "lancez ce script en root (sudo sh deploy.sh)."

say "1/7 Prérequis"
command -v git >/dev/null || { apt-get update -qq && apt-get install -y -qq git; }
command -v make >/dev/null || { apt-get update -qq && apt-get install -y -qq make; }
if ! command -v docker >/dev/null; then
  say "Installation de Docker (script officiel)"
  curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 est introuvable."

say "2/7 Le nom $DOMAIN doit pointer vers ce serveur"
SERVER_IP="$(curl -fsS https://api.ipify.org || true)"
DNS_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)"
echo "IP publique du serveur : ${SERVER_IP:-inconnue} ; IP de $DOMAIN : ${DNS_IP:-aucune}"
[ -n "$DNS_IP" ] || fail "aucun enregistrement DNS pour $DOMAIN : créez un enregistrement A vers l'IP du serveur, attendez sa propagation, puis relancez."
[ -z "$SERVER_IP" ] || [ "$SERVER_IP" = "$DNS_IP" ] || fail "$DOMAIN pointe vers $DNS_IP, pas vers ce serveur ($SERVER_IP) : corrigez le DNS puis relancez."

say "3/7 Code ($BRANCH)"
if [ -d "$DIR/.git" ]; then
  git -C "$DIR" fetch --quiet origin "$BRANCH"
  git -C "$DIR" checkout --quiet "$BRANCH"
  git -C "$DIR" reset --hard --quiet "origin/$BRANCH"
else
  git clone --quiet --branch "$BRANCH" "$REPO" "$DIR"
fi
cd "$DIR"

say "4/7 Environnement (clé, mots de passe de base de données : jamais dans Git)"
if [ ! -f .env ]; then sh scripts/install.sh; fi
grep -q '^APP_URL=' .env || printf 'APP_URL=https://%s\n' "$DOMAIN" >> .env
grep -q '^APP_ENV=' .env || printf 'APP_ENV=production\nAPP_DEBUG=false\n' >> .env

say "5/7 Construction et démarrage (plusieurs minutes la première fois)"
$COMPOSE up -d --build --wait

say "6/7 Comptes et données fictives (idempotent : aucun compte existant n'est modifié)"
umask 077
$COMPOSE exec -T php php artisan db:seed --force | tee /root/ikoma-comptes-demo.txt
echo "Mots de passe créés cette fois-ci : /root/ikoma-comptes-demo.txt (lisez-le, notez-les dans un gestionnaire de mots de passe, puis supprimez-le)."

say "7/7 HTTPS (Caddy)"
for port in 80 443; do
  owner="$(ss -ltnpH "sport = :$port" 2>/dev/null | grep -v caddy | head -1 || true)"
  [ -z "$owner" ] || fail "le port $port est déjà utilisé par un autre service ($owner). Ajoutez plutôt un site vers 127.0.0.1:8080 dans votre serveur web existant : voir docs/DEPLOIEMENT_DEMO.md, section « Serveur web déjà présent »."
done
if ! command -v caddy >/dev/null; then
  apt-get update -qq && apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gpg
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt -o /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -qq && apt-get install -y -qq caddy
fi
sed "s/ikoma.dgafrique.com/$DOMAIN/" deploy/demo/Caddyfile > /etc/caddy/Caddyfile
systemctl enable --now caddy >/dev/null 2>&1 || true
systemctl reload caddy
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; fi

say "Terminé"
echo "Ouvrez https://$DOMAIN (le certificat peut demander une minute)."
echo "Contrôle : curl -sI https://$DOMAIN/up | head -1   puis   cd $DIR && $COMPOSE ps"
