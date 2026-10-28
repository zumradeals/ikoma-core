#!/bin/bash
# Restauration d'une sauvegarde (lot L12). Par défaut : dans une base SÉPARÉE (ikoma_restauration), sans toucher à la production,
# puis contrôle de la chaîne d'audit et comparaison des effectifs. Remplacer la production est une option explicite.
#   bash deploy/prod/restore.sh --base ikoma-base-XXXX.dump.gpg --cle-privee cle-privee.asc [--phrase-secrete fichier] [--fichiers ikoma-fichiers-XXXX.tar.gpg] [--vers ikoma_restauration]
#   bash deploy/prod/restore.sh ... --remplacer-la-production      (arrête l'application, remplace la base et les fichiers, redémarre)
. "$(dirname "$0")/lib.sh"
DB_BACKUP=""; FILES_BACKUP=""; PRIVATE_KEY=""; PASSFILE=""; TARGET="ikoma_restauration"; REPLACE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --base) DB_BACKUP="$2"; shift 2 ;;
    --fichiers) FILES_BACKUP="$2"; shift 2 ;;
    --cle-privee) PRIVATE_KEY="$2"; shift 2 ;;
    --phrase-secrete) PASSFILE="$2"; shift 2 ;;
    --vers) TARGET="$2"; shift 2 ;;
    --remplacer-la-production) REPLACE=1; TARGET=ikoma; shift ;;
    *) fail "option inconnue : $1" ;;
  esac
done
[ -r "$DB_BACKUP" ] || fail "indiquez la sauvegarde de la base : --base fichier.dump.gpg"
[ -r "$PRIVATE_KEY" ] || fail "indiquez la clé privée de sauvegarde : --cle-privee fichier.asc"
case "$TARGET" in ikoma|ikoma_restauration|ikoma_restauration_[a-z0-9]*) ;; *) fail "nom de base non autorisé : $TARGET" ;; esac

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
export GNUPGHOME="$WORK/gnupg"; mkdir -m 0700 "$GNUPGHOME"
GPG=(gpg --batch --quiet --pinentry-mode loopback)
[ -z "$PASSFILE" ] || GPG+=(--passphrase-file "$PASSFILE")
"${GPG[@]}" --import "$PRIVATE_KEY"

psql_owner() { dc exec -T db psql -U ikoma -d "${1:-postgres}" -v ON_ERROR_STOP=1 -At "${@:2}"; }

if [ "$REPLACE" = 1 ]; then
  echo "ATTENTION : la base de production sera REMPLACÉE par cette sauvegarde. Les tablettes garderont leurs opérations en attente et les enverront au retour."
  read -r -p "Tapez REMPLACER pour continuer : " answer; [ "$answer" = REMPLACER ] || fail "abandon."
  say "Arrêt de l'application"
  dc stop nginx php scheduler
  psql_owner postgres -c "DROP DATABASE IF EXISTS ikoma WITH (FORCE)"
else
  psql_owner postgres -c "DROP DATABASE IF EXISTS $TARGET"
fi
psql_owner postgres -c "CREATE DATABASE $TARGET OWNER ikoma"

say "Restauration de la base dans $TARGET"
"${GPG[@]}" --decrypt "$DB_BACKUP" | dc exec -T db pg_restore -U ikoma -d "$TARGET" --no-owner --exit-on-error

say "Contrôle : chaîne d'audit"
dc run --rm --no-deps -e DB_DATABASE="$TARGET" migrator sh -c 'DB_USERNAME=ikoma DB_PASSWORD="$(cat /run/secrets/db_owner)" php artisan audit:verify'

say "Contrôle : effectifs (relevés à la sauvegarde / base restaurée)"
STATUS=0
COUNTS_FILE="${DB_BACKUP/-base-/-effectifs-}"; COUNTS_FILE="${COUNTS_FILE%.dump.gpg}.txt"
if [ -r "$COUNTS_FILE" ]; then
  while read -r table expected; do
    restored="$(psql_owner "$TARGET" -c "SELECT count(*) FROM $table")"
    if [ "$expected" = "$restored" ]; then echo "  ✓ $table : $restored"; else echo "  ✗ $table : $expected attendues, $restored restaurées"; STATUS=1; fi
  done < "$COUNTS_FILE"
else
  echo "  (fichier d'effectifs introuvable à côté de la sauvegarde : contrôle des effectifs non fait)"
fi

if [ -n "$FILES_BACKUP" ]; then
  say "Restauration des fichiers"
  if [ "$REPLACE" = 1 ]; then
    "${GPG[@]}" --decrypt "$FILES_BACKUP" | dc run --rm --no-deps -T php tar -C /var/www/html/storage/app -xf -
  else
    mkdir -p "$WORK/fichiers"; "${GPG[@]}" --decrypt "$FILES_BACKUP" | tar -C "$WORK/fichiers" -xf -
    echo "  ✓ archive lisible : $(find "$WORK/fichiers" -type f | wc -l) fichier(s) (non copiés : restauration dans une base séparée)"
  fi
fi

if [ "$REPLACE" = 1 ]; then
  say "Droits de la base et redémarrage"
  dc run --rm --no-deps migrator sh scripts/migrate-prod.sh
  dc up -d
  say "Restauration terminée. Vérifiez : bash $ROOT/deploy/prod/smoke.sh"
else
  echo; echo "Base restaurée « $TARGET » : elle reste sur le serveur pour examen. Pour la supprimer : docker compose exec db psql -U ikoma -d postgres -c "DROP DATABASE $TARGET"."
fi
[ "$STATUS" = 0 ] || fail "la restauration ne correspond pas à la sauvegarde : ne l'utilisez pas."
