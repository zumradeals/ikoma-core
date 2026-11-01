#!/bin/sh
# Inventory the *installed* packages inside the final image (not the build stage).
# Alpine emits "package-version" per line; Debian emits "package<TAB>version".
# The originating package manager is recorded explicitly in the first line.
set -eu

if command -v apk >/dev/null 2>&1; then
    echo '# package-manager=apk; format=package-version'
    apk info -v
elif command -v dpkg-query >/dev/null 2>&1; then
    echo '# package-manager=dpkg; format=package-tab-version'
    dpkg-query -W -f='${binary:Package}\t${Version}\n'
else
    echo 'R06 : gestionnaire inconnu, inventaire refusé.' >&2
    exit 1
fi
