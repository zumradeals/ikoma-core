<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R5a — Traçabilité des tirages de l'Atelier des badges : migration strictement additive.
 *
 * - badge_print_runs : un tirage (document généré) avec ses paramètres et sa nature (officiel ou essai). Immuable.
 * - badge_print_events : états de TRAÇABILITÉ d'un tirage (genere, demande, confirme), en ajout seul.
 * Ces états ne sont jamais des états métier : aucun lien avec les statuts des badges, des associations ou des cartes.
 * Aucune clé étrangère vers les événements ou les lots (les purges et remises à zéro existantes restent inchangées) ;
 * seul l'opérateur est référencé. Le journal d'audit reste sans donnée personnelle ; le motif libre reste ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_print_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 12)->default('visiteur');
            $t->uuid('event_id')->nullable();
            $t->uuid('batch_id')->nullable();
            $t->string('format', 12);
            $t->string('side', 6);
            $t->string('design', 12)->nullable();
            $t->string('mode', 12)->nullable();
            $t->string('nature', 8);
            $t->integer('planned_count');
            $t->integer('first_number')->nullable();
            $t->integer('last_number')->nullable();
            $t->string('reason', 200)->nullable();
            $t->jsonb('options');
            $t->uuid('created_by')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['batch_id', 'created_at']);
            $t->index('created_at');
        });
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_format_valid CHECK (format IN ('pvc', 'a4', 'etiquettes'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_side_valid CHECK (side IN ('recto', 'verso'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_valid CHECK (nature IN ('officiel', 'essai'))");
        DB::statement('ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_count_valid CHECK (planned_count >= 1)');
        // Un essai n'utilise jamais un vrai lot ; un tirage officiel en désigne toujours un.
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((nature = 'essai') = (batch_id IS NULL))");

        Schema::create('badge_print_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id');
            $t->string('state', 10);
            $t->uuid('actor_id')->nullable();
            $t->integer('printed')->nullable();
            $t->integer('spoiled')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestampTz('created_at');
            $t->foreign('run_id')->references('id')->on('badge_print_runs')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['run_id', 'created_at']);
        });
        DB::statement("ALTER TABLE badge_print_events ADD CONSTRAINT badge_print_events_state_valid CHECK (state IN ('genere', 'demande', 'confirme'))");
        DB::statement("ALTER TABLE badge_print_events ADD CONSTRAINT badge_print_events_counts CHECK ((state = 'confirme') = (printed IS NOT NULL AND spoiled IS NOT NULL) AND (printed IS NULL OR printed >= 0) AND (spoiled IS NULL OR spoiled >= 0))");
        // Un seul « généré » et une seule « confirmée » par tirage ; plusieurs « demandée » sont possibles (nouvelle tentative d'impression).
        DB::statement("CREATE UNIQUE INDEX badge_print_events_one_genere ON badge_print_events (run_id) WHERE state = 'genere'");
        DB::statement("CREATE UNIQUE INDEX badge_print_events_one_confirme ON badge_print_events (run_id) WHERE state = 'confirme'");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_print_trail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        IF EXISTS (SELECT 1 FROM public.badge_print_runs) OR EXISTS (SELECT 1 FROM public.badge_print_events) THEN
            RAISE EXCEPTION 'L''historique des tirages de badges ne se remet pas à zéro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NULL;
    END IF;
    RAISE EXCEPTION 'L''historique des tirages de badges est en ajout seul.' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_print_runs_append_only BEFORE UPDATE OR DELETE ON badge_print_runs FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_trail_guard();
CREATE TRIGGER badge_print_events_append_only BEFORE UPDATE OR DELETE ON badge_print_events FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_trail_guard();
CREATE TRIGGER badge_print_runs_no_truncate BEFORE TRUNCATE ON badge_print_runs FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_badge_print_trail_guard();
CREATE TRIGGER badge_print_events_no_truncate BEFORE TRUNCATE ON badge_print_events FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_badge_print_trail_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('badge_print_runs')->exists()) {
            throw new RuntimeException('Migration non réversible : des tirages de badges sont enregistrés.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS badge_print_events_no_truncate ON badge_print_events; DROP TRIGGER IF EXISTS badge_print_runs_no_truncate ON badge_print_runs; DROP TRIGGER IF EXISTS badge_print_events_append_only ON badge_print_events; DROP TRIGGER IF EXISTS badge_print_runs_append_only ON badge_print_runs;');
        Schema::dropIfExists('badge_print_events');
        Schema::dropIfExists('badge_print_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_badge_print_trail_guard();');
    }
};
