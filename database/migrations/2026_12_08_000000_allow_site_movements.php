<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablette de site, lot 3 : un mouvement peut appartenir au SITE (sans événement), dans le même journal `access_events`.
 * - `event_id` devient facultatif ; nouvelle colonne `holder_id` (titulaire permanent, clé simple, jamais supprimable tant qu'un mouvement existe).
 * - Un mouvement est soit d'activité (événement renseigné, `holder_id` vide : comme avant), soit du site (événement vide, ni participation ni badge ;
 *   `holder_id` obligatoire sauf pour un contrôle refusé sur une carte inconnue). Types du site : entrée, sortie, contrôle, correction.
 * - Les lignes existantes ne changent pas. La présence reste calculée depuis les mouvements, jamais stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_events ALTER COLUMN event_id DROP NOT NULL');
        Schema::table('access_events', function (Blueprint $t) {
            $t->uuid('holder_id')->nullable();
            $t->foreign('holder_id')->references('id')->on('permanent_holders')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_visitor_required');
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_visitor_required CHECK (visitor_id IS NOT NULL OR type = 'controle' OR holder_id IS NOT NULL)");
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_context_valid CHECK (event_id IS NOT NULL OR (visitor_id IS NULL AND badge_id IS NULL AND type IN ('entree', 'sortie', 'controle', 'correction')))");
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_holder_site_only CHECK (holder_id IS NULL OR event_id IS NULL)');
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_site_subject CHECK (event_id IS NOT NULL OR holder_id IS NOT NULL OR type = 'controle')");
        DB::statement("CREATE INDEX access_events_site_presence ON access_events (holder_id, occurred_at DESC) WHERE event_id IS NULL AND type IN ('entree', 'sortie')");
    }

    public function down(): void
    {
        if (DB::table('access_events')->whereNull('event_id')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des mouvements du site sont enregistrés.');
        }
        DB::statement('DROP INDEX IF EXISTS access_events_site_presence');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_site_subject');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_holder_site_only');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_context_valid');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_visitor_required');
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_visitor_required CHECK (visitor_id IS NOT NULL OR type = 'controle')");
        Schema::table('access_events', function (Blueprint $t) {
            $t->dropForeign(['holder_id']);
            $t->dropColumn('holder_id');
        });
        DB::statement('ALTER TABLE access_events ALTER COLUMN event_id SET NOT NULL');
    }
};
