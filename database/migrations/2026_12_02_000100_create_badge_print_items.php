<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badges et cartes — contrat d'IMPRESSION unique (strictement additif).
 *
 * `badge_print_items` : historique par support de toutes les nouvelles impressions (émission au premier tirage, réimpression motivée ensuite), rattaché à un tirage de
 * `badge_print_runs` (le traçage existant : document généré → demandé → confirmé par l'opérateur). Un support n'a qu'UNE émission d'impression (index unique partiel).
 * Les historiques antérieurs (`badge_print_runs` visiteurs et cartes, `holder_card_prints`) restent intacts et consultables ; aucune ligne n'est migrée ni supprimée.
 *
 * Nouveau type de tirage « support » (badge temporaire nominatif, éventuellement avec des cartes) : sans lot, sans réservation. Le type « permanent » (cartes seules) est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_print_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('print_run_id');
            $t->uuid('badge_id');
            $t->string('kind', 12);
            $t->string('reason', 200)->nullable();
            $t->uuid('printed_by')->nullable();
            $t->unsignedInteger('model_version')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('print_run_id')->references('id')->on('badge_print_runs')->restrictOnDelete();
            $t->index(['badge_id', 'created_at']);
        });
        DB::statement("ALTER TABLE badge_print_items ADD CONSTRAINT badge_print_items_kind_valid CHECK (kind IN ('emission', 'reimpression'))");
        DB::statement("ALTER TABLE badge_print_items ADD CONSTRAINT badge_print_items_reason_coherent CHECK ((kind = 'reimpression') = (reason IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX badge_print_items_one_emission ON badge_print_items (badge_id) WHERE kind = 'emission'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_print_item_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'L''historique d''impression d''un support ne se modifie pas.' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_print_items_immutable BEFORE UPDATE ON badge_print_items FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_item_immutable();
SQL);

        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_kind_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur', 'permanent', 'support'))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_nature_batch');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((kind IN ('permanent', 'support') AND batch_id IS NULL) OR (kind = 'visiteur' AND ((nature = 'essai') = (batch_id IS NULL))))");
    }

    public function down(): void
    {
        if (DB::table('badge_print_items')->exists() || DB::table('badge_print_runs')->where('kind', 'support')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des impressions de supports sont enregistrées.');
        }
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_nature_batch');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((kind = 'permanent' AND batch_id IS NULL) OR (kind = 'visiteur' AND ((nature = 'essai') = (batch_id IS NULL))))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_kind_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur', 'permanent'))");
        DB::unprepared('DROP TRIGGER IF EXISTS badge_print_items_immutable ON badge_print_items; DROP FUNCTION IF EXISTS public.ikoma_badge_print_item_immutable();');
        Schema::dropIfExists('badge_print_items');
    }
};
