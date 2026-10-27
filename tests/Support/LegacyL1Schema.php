<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/** Rejoue le contrat historique L1, sans modifier ni affaiblir FoundationTest. */
final class LegacyL1Schema
{
    private static ?string $schema = null;

    private static array $original = [];

    public static function start(): void
    {
        self::$original = config('database.connections.pgsql');
        self::$schema = 'regression_l1_'.str_replace('-', '', (string) Str::uuid());
        $owner = self::owner();
        $owner->exec('CREATE SCHEMA '.self::$schema);
        config(['database.connections.pgsql.username' => 'ikoma', 'database.connections.pgsql.password' => trim(file_get_contents(base_path('.secrets/db-owner'))), 'database.connections.pgsql.search_path' => self::$schema]);
        DB::purge('pgsql');
        foreach (['2026_10_28_000000_create_identity_tables.php', '2026_10_28_000001_create_permission_tables.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
        $schema = self::$schema;
        $owner->exec("GRANT USAGE ON SCHEMA $schema TO ikoma_app; GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA $schema TO ikoma_app; GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA $schema TO ikoma_app; REVOKE UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER ON $schema.audit_logs FROM ikoma_app");
        config(['database.connections.pgsql' => [...self::$original, 'search_path' => self::$schema]]);
        DB::purge('pgsql');
    }

    public static function finish(): void
    {
        DB::purge('pgsql');
        config(['database.connections.pgsql' => self::$original]);
        if (self::$schema) {
            self::owner()->exec('DROP SCHEMA '.self::$schema.' CASCADE');
        }
        self::$schema = null;
    }

    private static function owner(): PDO
    {
        return new PDO('pgsql:host=db;dbname=ikoma_test', 'ikoma', trim(file_get_contents(base_path('.secrets/db-owner'))), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
