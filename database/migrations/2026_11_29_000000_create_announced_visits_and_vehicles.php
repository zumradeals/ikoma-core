<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S09 — Visites annoncées, véhicules.
 *
 * - visitors (= participation) : heure d'arrivée attendue, qui a annoncé la visite et quand, et, si la personne ne s'est jamais présentée, une absence tracée
 *   (qui, quand, motif). L'annonce n'est pas une présence : le statut reste « attendu » jusqu'à l'entrée enregistrée par l'accueil ou le contrôle.
 * - vehicles : véhicule rattaché à UNE participation de l'activité (plaque normalisée, type). Un véhicule « annoncé » ou « présent » occupe sa plaque : la même
 *   plaque ne peut pas être affectée à une autre participation de la même activité tant qu'elle n'est pas sortie ou annulée, et un véhicule présent ne change
 *   jamais de participation (garde SQL).
 * - access_events : deux types de mouvement de véhicule, « vehicule_entree » et « vehicule_sortie ». Ils ne comptent jamais comme présence d'une personne.
 * - réglage visite_retard_minutes : délai après l'heure attendue au-delà duquel une visite est signalée en retard (modifiable en Administration).
 * - remise à zéro de démonstration : la table vehicles part avec les participations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $t) {
            $t->timestampTz('expected_at')->nullable();
            $t->uuid('announced_by')->nullable();
            $t->timestampTz('announced_at')->nullable();
            $t->timestampTz('no_show_at')->nullable();
            $t->uuid('no_show_by')->nullable();
            $t->string('no_show_reason', 200)->nullable();
            $t->foreign('announced_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('no_show_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_announcement_traced CHECK ((announced_at IS NULL) = (announced_by IS NULL) AND (expected_at IS NULL OR announced_at IS NOT NULL))');
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_no_show_traced CHECK ((no_show_at IS NULL) = (no_show_by IS NULL) AND (no_show_at IS NULL) = (no_show_reason IS NULL))');
        DB::statement('CREATE INDEX visitors_expected_idx ON visitors (event_id, expected_at) WHERE expected_at IS NOT NULL');

        Schema::create('vehicles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('visitor_id');
            $t->string('plate', 20);
            $t->string('plate_normalized', 20);
            $t->string('kind', 20);
            $t->string('status', 20)->default('annonce');
            $t->timestampTz('entered_at')->nullable();
            $t->timestampTz('exited_at')->nullable();
            $t->uuid('created_by');
            $t->uuid('entered_by')->nullable();
            $t->uuid('exited_by')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
            $t->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $t->foreign(['visitor_id', 'event_id'])->references(['id', 'event_id'])->on('visitors')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('entered_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('exited_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['event_id', 'plate_normalized']);
            $t->index(['visitor_id']);
        });
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_kind_valid CHECK (kind IN ('voiture','moto','minibus','camion','autre'))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_status_valid CHECK (status IN ('annonce','present','sorti','annule'))");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_plate_valid CHECK (plate_normalized ~ '^[A-Z0-9]{2,15}$')");
        DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_movements_traced CHECK ((status IN ('present','sorti')) = (entered_at IS NOT NULL) AND (status = 'sorti') = (exited_at IS NOT NULL))");
        // Une plaque occupée (annoncée ou présente) ne sert qu'une seule participation de l'activité à la fois.
        DB::statement("CREATE UNIQUE INDEX vehicles_active_plate_unique ON vehicles (event_id, plate_normalized) WHERE status IN ('annonce','present')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_vehicle_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status = 'present' AND (NEW.visitor_id IS DISTINCT FROM OLD.visitor_id OR NEW.plate_normalized IS DISTINCT FROM OLD.plate_normalized) THEN
        RAISE EXCEPTION 'Un véhicule présent ne change ni de participation ni de plaque : enregistrez d''abord sa sortie.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status IN ('sorti','annule') AND NEW.status IS DISTINCT FROM OLD.status THEN
        RAISE EXCEPTION 'Un véhicule sorti ou annulé ne change plus d''état.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER vehicles_guard BEFORE UPDATE ON vehicles FOR EACH ROW EXECUTE FUNCTION public.ikoma_vehicle_guard();
SQL);

        $types = array_values(array_unique([...$this->types(), 'vehicule_entree', 'vehicule_sortie']));
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_type_valid');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_type_valid CHECK (type IN ('.implode(', ', array_map(fn (string $t): string => "'".$t."'", $types)).'))');

        DB::table('settings')->insert(['key' => 'visite_retard_minutes', 'value' => '15']);

        // Remise à zéro de démonstration : les véhicules partent avant les participations auxquelles ils sont rattachés.
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.participation_entitlements, public.checkpoint_zones,', 'public.vehicles, public.participation_entitlements, public.checkpoint_zones,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);

        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        $event = str_replace(
            'DELETE FROM public.participation_entitlements WHERE event_id = p_event;',
            "DELETE FROM public.vehicles WHERE event_id = p_event;\n    DELETE FROM public.participation_entitlements WHERE event_id = p_event;",
            $event,
            $count,
        );
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (activité) inattendue : revue du contrat requise.');
        }
        DB::unprepared($event);
    }

    public function down(): void
    {
        if (DB::table('vehicles')->exists() || DB::table('visitors')->whereNotNull('announced_at')->exists() || DB::table('access_events')->whereIn('type', ['vehicule_entree', 'vehicule_sortie'])->exists()) {
            throw new RuntimeException('Migration non réversible : des visites annoncées, des véhicules ou des mouvements de véhicule existent.');
        }
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.vehicles, public.participation_entitlements,', 'public.participation_entitlements,', $business));
        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace("DELETE FROM public.vehicles WHERE event_id = p_event;\n    ", '', $event));
        DB::table('settings')->where('key', 'visite_retard_minutes')->delete();
        $types = array_values(array_diff($this->types(), ['vehicule_entree', 'vehicule_sortie']));
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_type_valid');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_type_valid CHECK (type IN ('.implode(', ', array_map(fn (string $t): string => "'".$t."'", $types)).'))');
        DB::unprepared('DROP TRIGGER IF EXISTS vehicles_guard ON vehicles; DROP FUNCTION IF EXISTS public.ikoma_vehicle_guard();');
        Schema::dropIfExists('vehicles');
        DB::statement('DROP INDEX IF EXISTS visitors_expected_idx');
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT visitors_no_show_traced');
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT visitors_announcement_traced');
        Schema::table('visitors', function (Blueprint $t) {
            $t->dropForeign(['announced_by']);
            $t->dropForeign(['no_show_by']);
            $t->dropColumn(['expected_at', 'announced_by', 'announced_at', 'no_show_at', 'no_show_by', 'no_show_reason']);
        });
    }

    /** @return list<string> */
    private function types(): array
    {
        $def = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'access_events_type_valid'")->d;
        preg_match_all("/'([^']+)'/", $def, $m);

        return array_values(array_unique($m[1]));
    }
};
