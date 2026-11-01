#!/bin/sh
# Déploiement (et mise à jour) de la démonstration IKOMA Accueil sur le serveur Contabo : https://ikoma.dgafrique.com
# À lancer en root sur le serveur : sh deploy.sh   (voir docs/DEPLOIEMENT_DEMO.md)
# Environnement de développement propriétaire : aucun jeu fictif créé automatiquement.
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
# La démonstration porte son propre environnement : « production » refuse les données de démonstration (lot L12).
if grep -q '^APP_ENV=' .env; then sed -i 's/^APP_ENV=.*/APP_ENV=demo/' .env; else printf 'APP_ENV=demo\nAPP_DEBUG=false\n' >> .env; fi

say "5/7 Construction et démarrage (plusieurs minutes la première fois)"
# Port local de l'application (127.0.0.1 seulement) : 8080 si libre, sinon le premier port libre suivant, puis mémorisé dans .env.
PORT="$(grep '^IKOMA_PORT=' .env | cut -d= -f2 || true)"
if [ -z "$PORT" ]; then
  PORT=8080
  while ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q .; do
    PORT=$((PORT + 1))
    [ "$PORT" -le 8199 ] || fail "aucun port local libre entre 8080 et 8199 sur ce serveur."
  done
  printf 'IKOMA_PORT=%s\n' "$PORT" >> .env
fi
if ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q . && ! $COMPOSE ps --status running 2>/dev/null | grep -q nginx; then
  fail "le port local $PORT (IKOMA_PORT dans $DIR/.env) est déjà utilisé par un autre service : supprimez la ligne IKOMA_PORT de .env puis relancez, un port libre sera choisi."
fi
echo "Port local de l'application : 127.0.0.1:$PORT"
$COMPOSE up -d --build --wait

say "6/7 Aucun réensemencement fictif automatique"
echo "Comptes, événements et visiteurs existants conservés. Aucun compte ou visiteur fictif créé."
echo "Pour retirer l'ancien jeu fictif après sauvegarde, consultez docs/SORTIE_MODE_DEMO.md."

say "7/7 HTTPS : site $DOMAIN devant l'application (127.0.0.1:$PORT)"
# Le serveur peut déjà héberger d'autres sites : on s'intègre au serveur web en place, sans toucher aux sites existants.
if systemctl is-active --quiet nginx 2>/dev/null; then WEB=nginx
elif systemctl is-active --quiet apache2 2>/dev/null; then WEB=apache
elif ! ss -ltnH 'sport = :80 or sport = :443' 2>/dev/null | grep -q .; then WEB=caddy
else fail "un service inconnu utilise déjà les ports 80/443. L'application tourne sur 127.0.0.1:$PORT : ajoutez un site vers cette adresse (voir docs/DEPLOIEMENT_DEMO.md)."
fi
echo "Serveur web détecté : $WEB"
CERT_ARGS="--non-interactive --agree-tos --redirect"
if [ -n "${EMAIL:-}" ]; then CERT_ARGS="$CERT_ARGS -m $EMAIL"; else CERT_ARGS="$CERT_ARGS --register-unsafely-without-email"; fi
case "$WEB" in
  nginx)
    if [ -d /etc/nginx/sites-available ]; then CONF=/etc/nginx/sites-available/ikoma-demo.conf; else CONF=/etc/nginx/conf.d/ikoma-demo.conf; fi
    # Une mise à jour de l'application ne doit JAMAIS recréer le vhost HTTP ni réémettre un certificat
    # d'un domaine déjà configuré. Cela effacerait le bloc 443 posé par Certbot et casserait le HTTPS.
    if [ -f "$CONF" ]; then
      nginx -t || fail "Nginx a une configuration invalide ; aucun vhost IKOMA n'a été modifié."
      echo "Vhost existant préservé : $CONF ; aucun Certbot lancé."
      # Vérifie le certificat réellement servi en local (SNI + nom de domaine), sans ignorer TLS.
      # Ne cherche jamais à réparer un problème Nginx en écrasant le vhost sur un VPS mutualisé.
      if ! curl --noproxy '*' --fail --silent --show-error --max-time 15 \
        --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/sante" >/dev/null; then
        fail "HTTPS existant invalide sur $DOMAIN : vérifiez les vhosts Nginx et le certificat présenté (SNI). Le déploiement n'a pas touché à la configuration TLS."
      fi
    else
      # Première installation uniquement : créer le HTTP nécessaire au défi ACME, puis laisser
      # Certbot établir le HTTPS. Le script n'écrase jamais de vhost IKOMA préexistant.
      cat > "$CONF" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    client_max_body_size 6m;
    location / {
        proxy_pass http://127.0.0.1:$PORT;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
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
      nginx -t && systemctl reload nginx
      curl --noproxy '*' --fail --silent --show-error --max-time 15 \
        --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/sante" >/dev/null \
        || fail "Le certificat a été demandé mais HTTPS ne répond pas correctement sur $DOMAIN."
    fi ;;
  apache)
    a2enmod -q proxy proxy_http headers rewrite ssl >/dev/null
    cat > /etc/apache2/sites-available/ikoma-demo.conf <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}
    ProxyPass / http://127.0.0.1:$PORT/
    ProxyPassReverse / http://127.0.0.1:$PORT/
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
    sed -e "s/ikoma.dgafrique.com/$DOMAIN/" -e "s/127.0.0.1:8080/127.0.0.1:$PORT/" deploy/demo/Caddyfile > /etc/caddy/Caddyfile
    systemctl enable --now caddy >/dev/null 2>&1 || true
    systemctl reload caddy ;;
esac
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; fi

say "Terminé"
echo "Ouvrez https://$DOMAIN (le certificat peut demander une minute)."
echo "Contrôle : curl -sI https://$DOMAIN/sante | head -1   puis   cd $DIR && $COMPOSE ps"
