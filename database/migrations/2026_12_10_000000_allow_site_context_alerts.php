<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tablette de site, lot 5 : une tablette sans activité (mode site) peut produire des alertes et des conflits de synchronisation sans événement.
 * `alerts.event_id` et `sync_conflicts.event_id` deviennent facultatifs ; les lignes existantes ne changent pas. Les écrans d'activité filtrent par événement
 * et ne voient donc pas ces lignes : leur lecture côté superviseur vient avec l'écran « Site ».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE alerts ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('ALTER TABLE sync_conflicts ALTER COLUMN event_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('alerts')->whereNull('event_id')->exists() || DB::table('sync_conflicts')->whereNull('event_id')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des alertes ou des conflits du site existent.');
        }
        DB::statement('ALTER TABLE sync_conflicts ALTER COLUMN event_id SET NOT NULL');
        DB::statement('ALTER TABLE alerts ALTER COLUMN event_id SET NOT NULL');
    }
};
