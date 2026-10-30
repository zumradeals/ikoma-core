#!/bin/bash
# Appelé par la planification ; l'administrateur utilise uniquement Gestion des données.
. "$(dirname "$0")/lib.sh"
exec 9>"$DATA/maintenance.lock"
flock -n 9 || exit 0
pending=$(dc exec -T db psql -U ikoma -d ikoma -At -c "SELECT count(*) FROM maintenance_requests WHERE status IN ('en_attente','base_validee','fichiers_a_reprendre')")
[ "$pending" -gt 0 ] || exit 0
# Nouvelle sauvegarde complète avant traitement, puis vérification matérielle de son manifeste dans le processus isolé.
bash "$ROOT/deploy/prod/backup.sh"
dc run --rm --no-deps maintenance
