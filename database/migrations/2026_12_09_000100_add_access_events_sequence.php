<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Journal des mouvements : ordre d'insertion fiable. Les colonnes de date de `access_events` sont à la seconde (précision 0) : deux mouvements de la même
 * seconde (une entrée puis une sortie rapprochées) étaient à égalité et la présence dépendait de l'identifiant tiré au hasard. La colonne `seq` (identité,
 * croissante) départage ces égalités. La remise à zéro de démonstration la réinitialise déjà (TRUNCATE ... RESTART IDENTITY).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_events ADD COLUMN seq bigint GENERATED ALWAYS AS IDENTITY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_events DROP COLUMN seq');
    }
};
