<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S06 — Habilitations des participations et correspondance points de contrôle / zones.
 *
 * - checkpoint_zones : l'administrateur indique quelles zones un POINT de contrôle (un poste) garde. Rien n'est codé en dur : sans correspondance, un point de type
 *   « poste » ne juge pas la zone (comportement antérieur).
 * - participation_entitlements : habilitation à UNE zone, accordée à UNE participation (visitors) pour une période, en complément de la catégorie (category_zone).
 *   « standard » ou « exceptionnelle » ; l'exceptionnelle exige un motif et une durée limitée (réglage habilitation_exceptionnelle_max_heures). Elle se suspend,
 *   reprend ou se retire (jamais modifiée ni supprimée) ; accorder, suspendre, reprendre et retirer sont réservés à l'administrateur (routes) et audités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkpoint_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('device_id');
            $t->uuid('zone_id');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['device_id', 'zone_id']);
            $t->foreign(['device_id', 'event_id'])->references(['id', 'event_id'])->on('devices')->restrictOnDelete();
            $t->foreign(['zone_id', 'event_id'])->references(['id', 'event_id'])->on('zones')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index('event_id');
        });

        Schema::create('participation_entitlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('visitor_id');
            $t->uuid('zone_id');
            $t->string('kind', 14)->default('standard');
            $t->string('reason', 200)->nullable();
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_until');
            $t->uuid('granted_by');
            $t->timestampTz('suspended_at')->nullable();
            $t->uuid('suspended_by')->nullable();
            $t->string('suspension_reason', 200)->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->string('revocation_reason', 200)->nullable();
            $t->timestampsTz();
            $t->foreign(['visitor_id', 'event_id'])->references(['id', 'event_id'])->on('visitors')->restrictOnDelete();
            $t->foreign(['zone_id', 'event_id'])->references(['id', 'event_id'])->on('zones')->restrictOnDelete();
            $t->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('suspended_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['visitor_id', 'valid_until']);
            $t->index(['event_id', 'zone_id']);
        });
        DB::statement("ALTER TABLE participation_entitlements ADD CONSTRAINT participation_entitlements_kind_valid CHECK (kind IN ('standard', 'exceptionnelle'))");
        DB::statement('ALTER TABLE participation_entitlements ADD CONSTRAINT participation_entitlements_period CHECK (valid_until > valid_from)');
        DB::statement("ALTER TABLE participation_entitlements ADD CONSTRAINT participation_entitlements_exceptional_reason CHECK (kind <> 'exceptionnelle' OR (reason IS NOT NULL AND length(btrim(reason)) > 0))");
        DB::statement('ALTER TABLE participation_entitlements ADD CONSTRAINT participation_entitlements_revoked_coherent CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');
        DB::statement('ALTER TABLE participation_entitlements ADD CONSTRAINT participation_entitlements_suspended_coherent CHECK ((suspended_at IS NULL) = (suspended_by IS NULL))');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_participation_entitlement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_id IS DISTINCT FROM OLD.event_id OR NEW.visitor_id IS DISTINCT FROM OLD.visitor_id OR NEW.zone_id IS DISTINCT FROM OLD.zone_id
       OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.reason IS DISTINCT FROM OLD.reason OR NEW.valid_from IS DISTINCT FROM OLD.valid_from
       OR NEW.valid_until IS DISTINCT FROM OLD.valid_until OR NEW.granted_by IS DISTINCT FROM OLD.granted_by THEN
        RAISE EXCEPTION 'Une habilitation ne se modifie pas : elle se suspend, se retire, puis une nouvelle est accordée.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.revoked_at IS NOT NULL THEN
        RAISE EXCEPTION 'Une habilitation retirée ne se modifie plus.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER participation_entitlements_guard BEFORE UPDATE ON participation_entitlements FOR EACH ROW EXECUTE FUNCTION public.ikoma_participation_entitlement_guard();
SQL);

        DB::table('settings')->insert(['key' => 'habilitation_exceptionnelle_max_heures', 'value' => '24']);

        // Remise à zéro de démonstration : les deux nouvelles tables partent avec les participations et les postes.
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.badge_transfers, public.badge_assignments,', 'public.participation_entitlements, public.checkpoint_zones, public.badge_transfers, public.badge_assignments,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);

        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        $event = str_replace(
            'DELETE FROM public.visitors WHERE event_id = p_event;',
            "DELETE FROM public.participation_entitlements WHERE event_id = p_event;\n    DELETE FROM public.checkpoint_zones WHERE event_id = p_event;\n    DELETE FROM public.visitors WHERE event_id = p_event;",
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
        if (DB::table('participation_entitlements')->exists() || DB::table('checkpoint_zones')->exists()) {
            throw new RuntimeException('Migration non réversible : des habilitations ou des correspondances existent.');
        }
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.participation_entitlements, public.checkpoint_zones, ', '', $business));
        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace("DELETE FROM public.participation_entitlements WHERE event_id = p_event;\n    DELETE FROM public.checkpoint_zones WHERE event_id = p_event;\n    ", '', $event));
        DB::table('settings')->where('key', 'habilitation_exceptionnelle_max_heures')->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS participation_entitlements_guard ON participation_entitlements; DROP FUNCTION IF EXISTS public.ikoma_participation_entitlement_guard();');
        Schema::dropIfExists('participation_entitlements');
        Schema::dropIfExists('checkpoint_zones');
    }
};
