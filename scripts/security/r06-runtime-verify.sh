#!/bin/sh
# Recette sur image de production finalisee, sans reseau, secret ou donnees metier.
set -eu

if dpkg-query -W -f='${Status}' linux-libc-dev 2>/dev/null | grep -Fxq 'install ok installed'; then
    echo 'R06 : linux-libc-dev demeure installe dans le runtime PHP.' >&2
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

echo 'R06 : linux-libc-dev absent et extensions PHP chargees.'
