#!/bin/bash
# Sauvegarde chiffrée quotidienne (lot L12) : base de données + fichiers (photos chiffrées, imports), chiffrés avec une clé PUBLIQUE.
# La clé privée n'est jamais sur ce serveur : sans elle, personne ne peut relire les sauvegardes, pas même un voleur du serveur.
# Le résultat (réussi ou échoué) est enregistré dans l'application : une alerte s'affiche chez l'administrateur si la dernière sauvegarde a plus de 26 heures ou a échoué.
. "$(dirname "$0")/lib.sh"
CONF="$DATA/backup.conf"
BACKUP_RETENTION_DAYS=14; BACKUP_COPY_COMMAND=""; BACKUP_DIR="$DATA/backups"
# shellcheck disable=SC1090
[ ! -f "$CONF" ] || . "$CONF"
KEY="${BACKUP_PUBLIC_KEY:-$DATA/backup-public.asc}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$(mktemp -d)"
FILES=()

record() { dc exec -T php php artisan sauvegarde:enregistrer "$1" >/dev/null 2>&1 || echo "Avertissement : résultat « $1 » non enregistré dans l'application." >&2; }
cleanup() { rm -rf "$WORK"; }
on_error() { echo "ÉCHEC de la sauvegarde ($STAMP)." >&2; record echec; cleanup; exit 1; }
trap on_error ERR
trap cleanup EXIT

[ -r "$KEY" ] || fail "clé publique de sauvegarde introuvable : $KEY"
umask 077
mkdir -p "$BACKUP_DIR"
export GNUPGHOME="$WORK/gnupg"; mkdir -m 0700 "$GNUPGHOME"
gpg --batch --quiet --import "$KEY"
FPR="$(gpg --batch --list-keys --with-colons | awk -F: '/^fpr:/{print $10; exit}')"
[ -n "$FPR" ] || fail "empreinte de la clé introuvable."
encrypt() { gpg --batch --yes --quiet --trust-model always --encrypt --recipient "$FPR" --cipher-algo AES256 --output "$1"; }

say "Sauvegarde $STAMP : effectifs (contrôle de la restauration)"
COUNTS_FILE="$BACKUP_DIR/ikoma-effectifs-$STAMP.txt"
# Nombres de lignes de quelques tables, relevés juste avant l'export : aucune donnée personnelle. La restauration les compare.
dc exec -T db psql -U ikoma -d ikoma -At -F ' ' -c "SELECT 'users', count(*) FROM users UNION ALL SELECT 'visitors', count(*) FROM visitors UNION ALL SELECT 'badges', count(*) FROM badges UNION ALL SELECT 'access_events', count(*) FROM access_events UNION ALL SELECT 'identity_checks', count(*) FROM identity_checks UNION ALL SELECT 'photos', count(*) FROM photos UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs" > "$COUNTS_FILE"
FILES+=("$COUNTS_FILE")

say "Sauvegarde $STAMP : base de données"
DB_FILE="$BACKUP_DIR/ikoma-base-$STAMP.dump.gpg"
dc exec -T db pg_dump -U ikoma -d ikoma --format=custom --no-owner | encrypt "$DB_FILE"
FILES+=("$DB_FILE")

say "Sauvegarde $STAMP : fichiers (photos chiffrées, imports)"
FILES_FILE="$BACKUP_DIR/ikoma-fichiers-$STAMP.tar.gpg"
dc exec -T php tar -C /var/www/html/storage/app -cf - . | encrypt "$FILES_FILE"
FILES+=("$FILES_FILE")

for file in "${FILES[@]}"; do
  [ -s "$file" ] || fail "fichier de sauvegarde vide : $file"
  (cd "$BACKUP_DIR" && sha256sum "$(basename "$file")") >> "$BACKUP_DIR/ikoma-$STAMP.sha256"
done
FILES+=("$BACKUP_DIR/ikoma-$STAMP.sha256")

if [ -n "$BACKUP_COPY_COMMAND" ]; then
  say "Copie vers l'emplacement externe"
  for file in "${FILES[@]}"; do bash -c "$BACKUP_COPY_COMMAND" _ "$file"; done
else
  echo "Aucune copie externe configurée (BACKUP_COPY_COMMAND) : la sauvegarde ne protège pas contre la perte du serveur."
fi

say "Rétention : suppression des sauvegardes locales de plus de $BACKUP_RETENTION_DAYS jours"
find "$BACKUP_DIR" -maxdepth 1 -name 'ikoma-*' -type f -mtime "+$BACKUP_RETENTION_DAYS" -delete

record ok
echo "Sauvegarde $STAMP réussie : $(basename "$DB_FILE"), $(basename "$FILES_FILE")"
