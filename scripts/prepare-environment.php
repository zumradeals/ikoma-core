<?php

// Exécuté uniquement par le conteneur d’installation, avant le démarrage.
$existing = is_file('.env') ? parse_ini_file('.env', false, INI_SCANNER_RAW) : [];
if (! is_dir('.secrets')) {
    mkdir('.secrets', 0700);
}
if (! is_file('.secrets/db-owner')) {
    if (($existing['DB_USERNAME'] ?? '') === 'ikoma_app') {
        throw new RuntimeException('Secret propriétaire manquant : restaurer .secrets/db-owner.');
    }
    file_put_contents('.secrets/db-owner', $existing['DB_PASSWORD'] ?? bin2hex(random_bytes(24)));
    chmod('.secrets/db-owner', 0600);
}
if (($existing['DB_USERNAME'] ?? '') !== 'ikoma_app') {
    $key = $existing['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32));
    $password = bin2hex(random_bytes(24));
    file_put_contents('.env', "APP_ENV=local\nAPP_KEY=$key\nDB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=5432\nDB_DATABASE=ikoma\nDB_USERNAME=ikoma_app\nDB_PASSWORD=$password\n");
    chown('.env', (int) getenv('IKOMA_LOCAL_UID'));
    chgrp('.env', 33);
    chmod('.env', 0640);
}
