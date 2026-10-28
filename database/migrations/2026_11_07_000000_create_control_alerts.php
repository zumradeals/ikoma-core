<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L14a — alertes du contrôle d'accès (audit global B2 et B3) : un badge refusé, une zone interdite, une double présentation
 * ou une rafale de refus préviennent le superviseur. Les trois seuils sont des réglages d'Administration, jamais des constantes.
 */
return new class extends Migration
{
    private const BEFORE = "'refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet','groupe_ecart'";

    private const ADDED = "'controle_refuse','zone_interdite','badge_double','controle_rafale'";

    public function up(): void
    {
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::BEFORE.','.self::ADDED.'))');
        // Valeurs de départ proposées, à confirmer avec le client : 15 s entre deux points distincts, 5 refus en 5 minutes.
        DB::table('settings')->insert([
            ['key' => 'controle_delai_min_points', 'value' => '15'],
            ['key' => 'controle_rafale_nombre', 'value' => '5'],
            ['key' => 'controle_rafale_minutes', 'value' => '5'],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['controle_delai_min_points', 'controle_rafale_nombre', 'controle_rafale_minutes'])->delete();
        DB::table('alerts')->whereIn('type', ['controle_refuse', 'zone_interdite', 'badge_double', 'controle_rafale'])->delete();
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::BEFORE.'))');
    }
};
