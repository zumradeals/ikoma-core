#!/bin/bash
# Fonctions communes aux scripts de production (lot L12). À inclure : . "$(dirname "$0")/lib.sh"
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA="$ROOT/prod-data"

say() { printf '\n==> %s\n' "$1"; }
fail() { printf '\nERREUR : %s\n' "$1" >&2; exit 1; }

# docker compose avec le fichier de production et les réglages du dossier prod-data.
dc() { docker compose --project-directory "$ROOT" --env-file "$DATA/env" -f "$ROOT/docker-compose.prod.yml" "$@"; }

# Valeur d'un réglage de prod-data/env (ou valeur par défaut).
env_value() { local value; value="$(grep -E "^$1=" "$DATA/env" 2>/dev/null | head -n1 | cut -d= -f2- || true)"; printf '%s' "${value:-${2:-}}"; }

# Exécute une commande avec les droits nécessaires pour donner un fichier à un utilisateur du conteneur (root, ou sudo sans mot de passe).
as_root() { if [ "$(id -u)" -eq 0 ]; then "$@"; elif command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then sudo "$@"; else fail "cette étape demande les droits administrateur : relancez avec sudo."; fi; }
