<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retrait du registre technique des cartes, étape 2 : le code d'un badge du site (sans événement) est unique au site.
 * L'unicité existante (événement, code) ne protège rien quand l'événement est vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX badges_site_code ON badges (code) WHERE event_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS badges_site_code');
    }
};
