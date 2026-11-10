<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablette de site, lot 2 : habilitations permanentes liées au SITE (par zone du site) et rattachement des zones du site aux postes de contrôle.
 * - holder_site_entitlements : (titulaire, zone du site, période), sans événement. Mêmes règles que `holder_entitlements` : une habilitation ne se modifie pas,
 *   elle se retire (motif) ; elle ne se supprime jamais. Seule une zone SANS événement peut être visée (garde-fou en base).
 * - checkpoint_site_zones : les zones du site qu'un poste de contrôle garde (même idée que `checkpoint_zones`, côté site).
 * Les moteurs restent distincts : rien n'existant ne change, aucune habilitation d'activité n'est reprise ni déplacée.
 * Remise à zéro de démonstration : les deux tables partent avec les zones (TRUNCATE sans CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_require_site_zone() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.zones WHERE id = NEW.zone_id AND event_id IS NULL) THEN
        RAISE EXCEPTION 'Seule une zone du site (sans événement) peut être visée ici.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION public.ikoma_site_entitlement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Une habilitation ne se supprime pas.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.holder_id IS DISTINCT FROM OLD.holder_id OR NEW.zone_id IS DISTINCT FROM OLD.zone_id OR NEW.valid_from IS DISTINCT FROM OLD.valid_from
       OR NEW.valid_until IS DISTINCT FROM OLD.valid_until OR NEW.granted_by IS DISTINCT FROM OLD.granted_by THEN
        RAISE EXCEPTION 'Une habilitation ne se modifie pas : elle se retire, puis une nouvelle est accordée.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.revoked_at IS NOT NULL THEN
        RAISE EXCEPTION 'Une habilitation retirée ne se modifie plus.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
SQL);

        Schema::create('holder_site_entitlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('holder_id');
            $t->uuid('zone_id');
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_until');
            $t->uuid('granted_by')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->string('revocation_reason', 200)->nullable();
            $t->timestampsTz();
            $t->foreign('holder_id')->references('id')->on('permanent_holders')->restrictOnDelete();
            $t->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete();
            $t->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['zone_id', 'holder_id']);
            $t->index('holder_id');
        });
        DB::statement('ALTER TABLE holder_site_entitlements ADD CONSTRAINT holder_site_entitlements_period CHECK (valid_until > valid_from)');
        DB::statement('ALTER TABLE holder_site_entitlements ADD CONSTRAINT holder_site_entitlements_revoked_coherent CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER holder_site_entitlements_site_zone BEFORE INSERT ON holder_site_entitlements FOR EACH ROW EXECUTE FUNCTION public.ikoma_require_site_zone();
CREATE TRIGGER holder_site_entitlements_guard BEFORE UPDATE OR DELETE ON holder_site_entitlements FOR EACH ROW EXECUTE FUNCTION public.ikoma_site_entitlement_guard();
SQL);

        Schema::create('checkpoint_site_zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('checkpoint_id');
            $t->uuid('zone_id');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->foreign('checkpoint_id')->references('id')->on('checkpoints')->restrictOnDelete();
            $t->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['checkpoint_id', 'zone_id']);
        });
        DB::unprepared('CREATE TRIGGER checkpoint_site_zones_site_zone BEFORE INSERT ON checkpoint_site_zones FOR EACH ROW EXECUTE FUNCTION public.ikoma_require_site_zone();');

        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.categories, public.zones, public.events,', 'public.categories, public.holder_site_entitlements, public.checkpoint_site_zones, public.zones, public.events,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);
    }

    public function down(): void
    {
        if (DB::table('holder_site_entitlements')->exists() || DB::table('checkpoint_site_zones')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des habilitations ou des rattachements du site existent.');
        }
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.holder_site_entitlements, public.checkpoint_site_zones, ', '', $business));
        Schema::dropIfExists('checkpoint_site_zones');
        Schema::dropIfExists('holder_site_entitlements');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_site_entitlement_guard(); DROP FUNCTION IF EXISTS public.ikoma_require_site_zone();');
    }
};
