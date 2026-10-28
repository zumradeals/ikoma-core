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
if ss -ltnH 'sport = :8080' 2>/dev/null | grep -q . && ! $COMPOSE ps --status running 2>/dev/null | grep -q nginx; then
  fail "le port local 8080 est déjà utilisé par un autre service de ce serveur. Libérez-le ou demandez à l'architecte de changer le port."
fi
$COMPOSE up -d --build --wait

say "6/7 Comptes et données fictives (idempotent : aucun compte existant n'est modifié)"
umask 077
$COMPOSE exec -T php php artisan db:seed --force | tee /root/ikoma-comptes-demo.txt
echo "Mots de passe créés cette fois-ci : /root/ikoma-comptes-demo.txt (lisez-le, notez-les dans un gestionnaire de mots de passe, puis supprimez-le)."

say "7/7 HTTPS : site $DOMAIN devant l'application (127.0.0.1:8080)"
# Le serveur peut déjà héberger d'autres sites : on s'intègre au serveur web en place, sans toucher aux sites existants.
if systemctl is-active --quiet nginx 2>/dev/null; then WEB=nginx
elif systemctl is-active --quiet apache2 2>/dev/null; then WEB=apache
elif ! ss -ltnH 'sport = :80 or sport = :443' 2>/dev/null | grep -q .; then WEB=caddy
else fail "un service inconnu utilise déjà les ports 80/443. L'application tourne sur 127.0.0.1:8080 : ajoutez un site vers cette adresse (voir docs/DEPLOIEMENT_DEMO.md)."
fi
echo "Serveur web détecté : $WEB"
CERT_ARGS="--non-interactive --agree-tos --redirect"
if [ -n "${EMAIL:-}" ]; then CERT_ARGS="$CERT_ARGS -m $EMAIL"; else CERT_ARGS="$CERT_ARGS --register-unsafely-without-email"; fi
case "$WEB" in
  nginx)
    if [ -d /etc/nginx/sites-available ]; then CONF=/etc/nginx/sites-available/ikoma-demo.conf; else CONF=/etc/nginx/conf.d/ikoma-demo.conf; fi
    cat > "$CONF" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    client_max_body_size 6m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX
    if [ -d /etc/nginx/sites-enabled ]; then ln -sf "$CONF" /etc/nginx/sites-enabled/ikoma-demo.conf; fi
    if ! nginx -t 2>/dev/null; then rm -f "$CONF" /etc/nginx/sites-enabled/ikoma-demo.conf; fail "la configuration nginx a été refusée ; elle a été retirée, vos autres sites ne sont pas touchés."; fi
    systemctl reload nginx
    command -v certbot >/dev/null || { apt-get update -qq && apt-get install -y -qq certbot python3-certbot-nginx; }
    # shellcheck disable=SC2086
    certbot --nginx -d "$DOMAIN" $CERT_ARGS
    nginx -t && systemctl reload nginx ;;
  apache)
    a2enmod -q proxy proxy_http headers rewrite ssl >/dev/null
    cat > /etc/apache2/sites-available/ikoma-demo.conf <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
    ProxyPass / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
</VirtualHost>
APACHE
    a2ensite -q ikoma-demo >/dev/null
    if ! apache2ctl configtest 2>/dev/null; then a2dissite -q ikoma-demo >/dev/null; rm -f /etc/apache2/sites-available/ikoma-demo.conf; fail "la configuration Apache a été refusée ; elle a été retirée."; fi
    systemctl reload apache2
    command -v certbot >/dev/null || { apt-get update -qq && apt-get install -y -qq certbot python3-certbot-apache; }
    # shellcheck disable=SC2086
    certbot --apache -d "$DOMAIN" $CERT_ARGS
    systemctl reload apache2 ;;
  caddy)
    if ! command -v caddy >/dev/null; then
      apt-get update -qq && apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gpg
      curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
      curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt -o /etc/apt/sources.list.d/caddy-stable.list
      apt-get update -qq && apt-get install -y -qq caddy
    fi
    sed "s/ikoma.dgafrique.com/$DOMAIN/" deploy/demo/Caddyfile > /etc/caddy/Caddyfile
    systemctl enable --now caddy >/dev/null 2>&1 || true
    systemctl reload caddy ;;
esac
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; fi

say "Terminé"
echo "Ouvrez https://$DOMAIN (le certificat peut demander une minute)."
echo "Contrôle : curl -sI https://$DOMAIN/up | head -1   puis   cd $DIR && $COMPOSE ps"
