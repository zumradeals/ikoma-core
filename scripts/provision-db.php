<?php

$env = parse_ini_file('.env', false, INI_SCANNER_RAW);
$database = getenv('DB_DATABASE') ?: 'ikoma';
if (! in_array($database, ['ikoma', 'ikoma_test'], true)) {
    throw new RuntimeException('Base non autorisée.');
}
$pdo = new PDO("pgsql:host=db;dbname=$database", 'ikoma', trim(file_get_contents('.secrets/db-owner')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! $pdo->query("SELECT 1 FROM pg_roles WHERE rolname='ikoma_app'")->fetchColumn()) {
    $pdo->exec('CREATE ROLE ikoma_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT');
}
$pdo->exec('ALTER ROLE ikoma_app PASSWORD '.$pdo->quote($env['DB_PASSWORD']));
$pdo->exec('GRANT CONNECT ON DATABASE '.$database.' TO ikoma_app');
