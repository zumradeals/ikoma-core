#!/bin/sh
# R06 — contrôle fail-closed de la surface de compilation du runtime PHP final.
# Réalisé dans le conteneur sans réseau, secrets, privilèges ni données métier.
set -eu

if command -v apk >/dev/null 2>&1; then
    # Alpine: aucune dépendance de compilation ne doit rester dans l'image.
    for package in linux-headers build-base gcc g++ make postgresql-dev oniguruma-dev libxml2-dev libzip-dev libjpeg-turbo-dev libpng-dev; do
        if apk info -e "$package" >/dev/null 2>&1; then
            echo "R06 : paquet de compilation interdit dans PHP Alpine : $package" >&2
            exit 1
        fi
    done
elif command -v dpkg-query >/dev/null 2>&1; then
    # Debian: conserver le contrôle historique du paquet d'en-têtes.
    if dpkg-query -W -f='${Status}' linux-libc-dev 2>/dev/null | grep -Fxq 'install ok installed'; then
        echo 'R06 : linux-libc-dev demeure installe dans le runtime PHP.' >&2
        exit 1
    fi
else
    echo 'R06 : gestionnaire de paquets non reconnu, preuve refusée.' >&2
    exit 1
fi

php -r '
foreach (["pdo_pgsql", "mbstring", "zip", "xmlreader", "gd", "libxml"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "R06 : extension PHP absente : " . $extension . PHP_EOL);
        exit(1);
    }
}
'

echo 'R06 : dépendances de compilation absentes et extensions PHP chargées.'
