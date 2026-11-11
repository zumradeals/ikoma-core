<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zones du site réutilisées par les activités, étape Z5c (docs/ailct/V1-ZONES-DU-SITE.md) : retrait de l'ancien chemin.
 * Une zone n'appartient plus jamais à une activité : `zones.event_id` disparaît, avec les tables `checkpoint_zones` et `zone_policies`
 * (la garde d'une zone par un poste est dans `checkpoint_site_zones`, la politique d'une zone dans `site_zone_policies`) et avec les déclencheurs
 * qui distinguaient « zone du site » et « zone de l'activité ». Les fonctions de remise à zéro de la démonstration sont réécrites sans ces tables.
 * Par sécurité, les reprises Z3 (zones d'activité vers zones du site) et Z4b (zones des postes) sont rejouées d'abord : sans effet si tout est déjà repris.
 * Non réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_12_14_000000_move_activity_zones_to_site.php')->up();
        (require __DIR__.'/2026_12_15_000000_move_checkpoint_zones_to_site.php')->up();
        if (DB::table('zones')->whereNotNull('event_id')->exists() || DB::table('checkpoint_zones')->exists() || DB::table('zone_policies')->exists()) {
            throw new RuntimeException('Retrait interrompu : des zones d\'activité, correspondances ou politiques d\'activité subsistent après la reprise.');
        }

        // Les tables d'activité disparaissent d'abord (leurs déclencheurs dépendent des fonctions retirées plus bas).
        DB::statement('DROP TABLE public.checkpoint_zones');
        DB::statement('DROP TABLE public.zone_policies');

        // Plus de distinction zone du site / zone d'activité.
        foreach (['category_zone', 'participation_entitlements', 'holder_entitlements'] as $table) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_zone_of_event_or_site ON '.$table.';');
        }
        foreach (['checkpoint_site_zones', 'holder_site_entitlements', 'site_zone_policies'] as $table) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_site_zone ON '.$table.';');
        }
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_zone_of_event_or_site();');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_require_site_zone();');

        // Remises à zéro de la démonstration : plus de table ni de zone liée à une activité.
        foreach (['public.ikoma_reset_demo_business(uuid,boolean)', 'public.ikoma_reset_demo_event(uuid)'] as $signature) {
            $definition = (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [$signature])->d;
            $updated = preg_replace([
                '/\s*(?:DELETE FROM|TRUNCATE(?: TABLE)?)\s+public\.(?:checkpoint_zones|zone_policies)\b[^;]*;/',
                '/\s*DELETE FROM public\.zones WHERE event_id = p_event;/',
                '/public\.(?:checkpoint_zones|zone_policies)\s*,\s*/',
                '/\s*,\s*public\.(?:checkpoint_zones|zone_policies)\b/',
            ], '', $definition);
            if ($updated === null || preg_match('/.{0,120}(?:checkpoint_zones|zone_policies|zones WHERE event_id).{0,120}/s', $updated, $left) === 1) {
                throw new RuntimeException('Fonction de remise à zéro inattendue ('.$signature.') : revue du contrat requise. Reste : '.preg_replace('/\s+/', ' ', $left[0] ?? ''));
            }
            DB::unprepared($updated);
        }

        DB::statement('CREATE UNIQUE INDEX zones_site_name_unique_all ON public.zones (site_id, name)');
        DB::statement('ALTER TABLE public.zones DROP COLUMN event_id');
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : une zone n\'appartient plus à une activité.');
    }
};
