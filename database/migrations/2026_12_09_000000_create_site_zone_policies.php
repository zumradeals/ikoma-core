<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablette de site, lot 4a : politique d'une ZONE DU SITE (mêmes réglages que `zone_policies` d'une zone d'activité : classification, autorisée aux
 * titulaires permanents, entrée hors ligne acceptée). Sans politique, la zone refuse les titulaires permanents par défaut.
 * Seule une zone sans événement peut être visée (déclencheur du lot 2). Remise à zéro de démonstration : la table part avec les zones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_zone_policies', function (Blueprint $t) {
            $t->uuid('zone_id')->primary();
            $t->string('classification', 40);
            $t->boolean('permanent_allowed')->default(false);
            $t->boolean('offline_entry_allowed')->default(false);
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign('zone_id')->references('id')->on('zones')->restrictOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE site_zone_policies ADD CONSTRAINT site_zone_policies_offline_needs_permanent CHECK (permanent_allowed OR NOT offline_entry_allowed)');
        DB::unprepared('CREATE TRIGGER site_zone_policies_site_zone BEFORE INSERT ON site_zone_policies FOR EACH ROW EXECUTE FUNCTION public.ikoma_require_site_zone();');

        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.checkpoint_site_zones, public.zones,', 'public.checkpoint_site_zones, public.site_zone_policies, public.zones,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);
    }

    public function down(): void
    {
        if (DB::table('site_zone_policies')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des politiques de zones du site existent.');
        }
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.site_zone_policies, ', '', $business));
        Schema::dropIfExists('site_zone_policies');
    }
};
