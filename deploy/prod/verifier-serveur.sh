#!/bin/bash
# Contrôles de durcissement du serveur (lot L12) : LECTURE SEULE, ne modifie rien. Voir docs/DURCISSEMENT_SERVEUR.md.
#   sudo bash deploy/prod/verifier-serveur.sh
set -uo pipefail
FAILED=0; WARN=0
ok() { echo "  ✓ $1"; }
ko() { echo "  ✗ $1"; FAILED=1; }
avertir() { echo "  ⚠ $1"; WARN=1; }

echo "Contrôle du durcissement du serveur"
[ "$(id -u)" -eq 0 ] || avertir "lancé sans droits administrateur : certains contrôles seront incomplets (sudo)."

echo "— Accès distant (SSH)"
SSHD="$(sshd -T 2>/dev/null || true)"
if [ -z "$SSHD" ]; then avertir "configuration SSH illisible (sshd absent ou droits insuffisants)"; else
  grep -qi '^passwordauthentication no' <<<"$SSHD" && ok "connexion SSH par mot de passe désactivée (clés seulement)" || ko "SSH accepte les mots de passe : mettez PasswordAuthentication no"
  grep -qiE '^permitrootlogin (no|prohibit-password|without-password)' <<<"$SSHD" && ok "connexion directe en root interdite par mot de passe" || ko "SSH autorise root avec mot de passe : mettez PermitRootLogin no"
fi

echo "— Pare-feu"
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -qi '^Status: active'; then ok "ufw actif"
elif command -v nft >/dev/null && nft list ruleset 2>/dev/null | grep -q 'hook input'; then ok "règles nftables présentes"
elif command -v firewall-cmd >/dev/null && firewall-cmd --state 2>/dev/null | grep -q running; then ok "firewalld actif"
else ko "aucun pare-feu actif : n'autorisez que SSH (depuis les adresses d'administration), 80 et 443"; fi
LISTEN="$(ss -ltnH 2>/dev/null | awk '{print $4}' | sed 's/.*://' | sort -un | tr '\n' ' ')"
echo "    ports en écoute : ${LISTEN:-inconnus}"
for port in 5432 9000; do
  if ss -ltnH 2>/dev/null | awk '{print $4}' | grep -qE "(0\.0\.0\.0|\*|\[::\]):$port$"; then ko "le port $port est ouvert sur toutes les interfaces"; fi
done

echo "— Mises à jour de sécurité"
if [ -f /etc/apt/apt.conf.d/20auto-upgrades ] && grep -q 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades; then ok "mises à jour de sécurité automatiques (unattended-upgrades)"
elif command -v dnf-automatic >/dev/null || systemctl is-enabled dnf-automatic.timer >/dev/null 2>&1; then ok "dnf-automatic activé"
else ko "mises à jour de sécurité automatiques absentes"; fi
[ -e /var/run/reboot-required ] && avertir "un redémarrage est en attente (mise à jour du noyau)" || ok "aucun redémarrage en attente"

echo "— Heure"
if timedatectl show 2>/dev/null | grep -q '^NTPSynchronized=yes'; then ok "horloge synchronisée"; else ko "horloge non synchronisée : le journal d'audit et les tablettes en dépendent (chrony ou systemd-timesyncd)"; fi

echo "— Disque et comptes"
if lsblk -o TYPE 2>/dev/null | grep -q crypt; then ok "volume chiffré détecté (LUKS)"; else avertir "aucun volume chiffré détecté : chiffrez le disque (voir le guide) ou chiffrez au niveau de l'hébergeur"; fi
USED="$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')"
[ "${USED:-100}" -lt 80 ] && ok "disque racine utilisé à ${USED} %" || avertir "disque racine utilisé à ${USED} %"
if getent group docker >/dev/null; then echo "    membres du groupe docker (équivalent root) : $(getent group docker | cut -d: -f4)"; fi
EMPTY="$(awk -F: '($2=="" ){print $1}' /etc/shadow 2>/dev/null | tr '\n' ' ')"
[ -z "$EMPTY" ] && ok "aucun compte sans mot de passe" || ko "comptes sans mot de passe : $EMPTY"

echo "— IKOMA"
[ -d /opt/ikoma/prod-data/secrets ] && { P="$(stat -c '%a' /opt/ikoma/prod-data/secrets)"; [ "$P" = 700 ] && ok "dossier des secrets en 0700" || ko "dossier des secrets en $P (attendu 700)"; }
[ -d /opt/ikoma/prod-data/backups ] && { A="$(find /opt/ikoma/prod-data/backups -name 'ikoma-base-*.gpg' -mtime -2 2>/dev/null | head -n1)"; [ -n "$A" ] && ok "une sauvegarde de moins de 48 h existe" || ko "aucune sauvegarde de moins de 48 h"; }

echo
if [ "$FAILED" = 0 ] && [ "$WARN" = 0 ]; then echo "Serveur conforme."; elif [ "$FAILED" = 0 ]; then echo "Conforme, avec des avertissements à traiter."; else echo "Non conforme : corrigez les points ✗ avant la mise en service."; fi
exit "$FAILED"
