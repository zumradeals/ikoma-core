<?php

// Développement : .env et .secrets/db-owner. Production (L12) : variables d'environnement et fichiers de secrets montés par Docker.
$env = is_file('.env') ? parse_ini_file('.env', false, INI_SCANNER_RAW) : [];
$appPassword = getenv('IKOMA_DB_APP_PASSWORD') ?: ($env['DB_PASSWORD'] ?? throw new RuntimeException('Mot de passe applicatif manquant.'));
$ownerFile = getenv('DB_OWNER_PASSWORD_FILE') ?: '.secrets/db-owner';
$host = getenv('DB_HOST') ?: 'db';
$database = getenv('DB_DATABASE') ?: 'ikoma';
if (! in_array($database, ['ikoma', 'ikoma_test'], true)) {
    throw new RuntimeException('Base non autorisée.');
}
$pdo = new PDO("pgsql:host=$host;dbname=$database", 'ikoma', trim(file_get_contents($ownerFile)), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! $pdo->query("SELECT 1 FROM pg_roles WHERE rolname='ikoma_app'")->fetchColumn()) {
    $pdo->exec('CREATE ROLE ikoma_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT');
}
$pdo->exec('ALTER ROLE ikoma_app PASSWORD '.$pdo->quote($appPassword));
$pdo->exec('GRANT CONNECT ON DATABASE '.$database.' TO ikoma_app');
