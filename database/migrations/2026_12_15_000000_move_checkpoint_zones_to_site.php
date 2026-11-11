<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zones du site réutilisées par les activités, étape Z4b (docs/ailct/V1-ZONES-DU-SITE.md) : les zones qu'un poste garde se règlent à un seul endroit,
 * « Site et accès » (`checkpoint_site_zones`). Les correspondances poste–zone d'activité (`checkpoint_zones`) dont la zone est maintenant une zone du site
 * y sont reprises (sans doublon) puis retirées de l'ancienne table. Un poste juge alors les zones du site qu'il garde ET que l'activité réutilise.
 * Une correspondance vers une zone propre à une activité (ancien chemin, jeux d'essai) n'est pas touchée. Non réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
INSERT INTO public.checkpoint_site_zones (id, checkpoint_id, zone_id, created_by, created_at, updated_at)
SELECT gen_random_uuid(), m.checkpoint_id, m.zone_id, (array_agg(m.created_by ORDER BY m.created_at))[1], min(m.created_at), now()
FROM public.checkpoint_zones m
JOIN public.zones z ON z.id = m.zone_id AND z.event_id IS NULL
WHERE NOT EXISTS (SELECT 1 FROM public.checkpoint_site_zones s WHERE s.checkpoint_id = m.checkpoint_id AND s.zone_id = m.zone_id)
GROUP BY m.checkpoint_id, m.zone_id
SQL);
        DB::statement('ALTER TABLE public.checkpoint_zones DISABLE TRIGGER USER');
        DB::statement('DELETE FROM public.checkpoint_zones m USING public.zones z WHERE z.id = m.zone_id AND z.event_id IS NULL');
        DB::statement('ALTER TABLE public.checkpoint_zones ENABLE TRIGGER USER');
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : les zones gardées par les postes sont réglées dans Site et accès.');
    }
};
