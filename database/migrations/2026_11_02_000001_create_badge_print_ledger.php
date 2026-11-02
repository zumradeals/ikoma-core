<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R5a — Registre des tirages de l'Atelier des badges (parcours visiteurs) : migration strictement additive.
 *
 * - badge_print_runs : un tirage = un document généré, avec ses paramètres et sa nature (officiel ou essai). Immuable.
 * - badge_print_events : les trois états de TRAÇABILITÉ d'un tirage, en ajout seul :
 *     generated (document généré) → requested (impression demandée par l'opérateur) → operator_confirmed (impression physique confirmée par l'opérateur).
 *   Ces états ne sont jamais des états métier (statut d'un badge, association, réservation, carte, habilitation).
 *
 * Correction : operator_id référence users.id, qui est un UUID (l'ancienne déclaration foreignId() produisait un bigint, incompatible : SQLSTATE 42804).
 * Aucune clé étrangère vers events ni badge_batches : les purges et la remise à zéro de démonstration existantes restent inchangées
 * (un lot imprimé est de toute façon protégé par print_exposed_at). Seul l'opérateur est référencé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_print_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 12)->default('visiteur');
            $table->uuid('event_id');
            $table->uuid('batch_id')->nullable();
            $table->foreignUuid('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('nature', 8);
            $table->string('model', 80)->nullable();
            $table->string('mode', 12)->nullable();
            $table->string('format', 20);
            $table->string('face', 20);
            $table->unsignedInteger('badge_count');
            $table->string('first_code', 80)->nullable();
            $table->string('last_code', 80)->nullable();
            $table->integer('first_number')->nullable();
            $table->integer('last_number')->nullable();
            $table->string('reason', 200)->nullable();
            $table->jsonb('options');
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->index(['batch_id', 'generated_at']);
            $table->index('generated_at');
        });
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_format_valid CHECK (format IN ('pvc', 'a4', 'etiquettes'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_face_valid CHECK (face IN ('recto', 'verso'))");
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_valid CHECK (nature IN ('officiel', 'essai'))");
        DB::statement('ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_count_valid CHECK (badge_count >= 1)');
        // Un essai n'utilise jamais un vrai lot ; un tirage officiel en désigne toujours un.
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((nature = 'essai') = (batch_id IS NULL))");

        Schema::create('badge_print_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('print_run_id');
            $table->foreign('print_run_id')->references('id')->on('badge_print_runs')->restrictOnDelete();
            $table->foreignUuid('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->unsignedInteger('printed')->nullable();
            $table->unsignedInteger('spoiled')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestampTz('created_at');
            $table->index(['print_run_id', 'created_at']);
        });
        DB::statement("ALTER TABLE badge_print_events ADD CONSTRAINT badge_print_events_type_valid CHECK (event_type IN ('generated', 'requested', 'operator_confirmed'))");
        DB::statement("ALTER TABLE badge_print_events ADD CONSTRAINT badge_print_events_counts CHECK ((event_type = 'operator_confirmed') = (printed IS NOT NULL AND spoiled IS NOT NULL))");
        // Un seul « généré » et une seule « confirmée » par tirage ; plusieurs « demandée » sont possibles (nouvelle tentative d'impression).
        DB::statement("CREATE UNIQUE INDEX badge_print_events_one_generated ON badge_print_events (print_run_id) WHERE event_type = 'generated'");
        DB::statement("CREATE UNIQUE INDEX badge_print_events_one_confirmed ON badge_print_events (print_run_id) WHERE event_type = 'operator_confirmed'");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_print_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        IF EXISTS (SELECT 1 FROM public.badge_print_runs) OR EXISTS (SELECT 1 FROM public.badge_print_events) THEN
            RAISE EXCEPTION 'Historique des impressions immuable : aucune remise à zéro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NULL;
    END IF;
    RAISE EXCEPTION 'Historique des impressions immuable' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_print_runs_immutable BEFORE UPDATE OR DELETE ON badge_print_runs
FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_append_only();
CREATE TRIGGER badge_print_events_immutable BEFORE UPDATE OR DELETE ON badge_print_events
FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_append_only();
CREATE TRIGGER badge_print_runs_no_truncate BEFORE TRUNCATE ON badge_print_runs
FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_badge_print_append_only();
CREATE TRIGGER badge_print_events_no_truncate BEFORE TRUNCATE ON badge_print_events
FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_badge_print_append_only();
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('badge_print_runs') && DB::table('badge_print_runs')->exists()) {
            throw new RuntimeException('Migration non réversible : des tirages de badges sont enregistrés.');
        }
        DB::statement('DROP TRIGGER IF EXISTS badge_print_events_no_truncate ON badge_print_events');
        DB::statement('DROP TRIGGER IF EXISTS badge_print_runs_no_truncate ON badge_print_runs');
        DB::statement('DROP TRIGGER IF EXISTS badge_print_events_immutable ON badge_print_events');
        DB::statement('DROP TRIGGER IF EXISTS badge_print_runs_immutable ON badge_print_runs');
        Schema::dropIfExists('badge_print_events');
        Schema::dropIfExists('badge_print_runs');
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_badge_print_append_only()');
    }
};
