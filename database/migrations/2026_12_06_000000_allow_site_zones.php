<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tablette de site, lot 1 : une ZONE peut appartenir au site sans aucun événement (`zones.event_id` facultatif).
 * - Les zones d'activité ne changent pas (mêmes clés composites, mêmes noms uniques par événement).
 * - Une zone du site a un nom unique dans le site ; le déclencheur existant lui donne le site de l'Académie.
 * - Aucune table existante ne référence encore une zone du site : les habilitations et les mouvements du site viennent aux lots suivants.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE zones ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX zones_site_name_unique ON zones (site_id, name) WHERE event_id IS NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : des zones du site peuvent déjà exister.');
    }
};
