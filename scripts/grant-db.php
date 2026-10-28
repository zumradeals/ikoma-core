<?php

$database = getenv('DB_DATABASE') ?: 'ikoma';
if (! in_array($database, ['ikoma', 'ikoma_test'], true)) {
    throw new RuntimeException('Base non autorisée.');
}
$pdo = new PDO("pgsql:host=db;dbname=$database", 'ikoma', trim(file_get_contents('.secrets/db-owner')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC; GRANT USAGE ON SCHEMA public TO ikoma_app; GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO ikoma_app; GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO ikoma_app; REVOKE ALL ON audit_logs FROM PUBLIC; REVOKE UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON audit_logs FROM ikoma_app; GRANT SELECT,INSERT ON audit_logs TO ikoma_app; REVOKE INSERT,UPDATE,DELETE,TRUNCATE ON migrations FROM ikoma_app; REVOKE UPDATE,DELETE,TRUNCATE ON access_events, identity_checks FROM ikoma_app');
