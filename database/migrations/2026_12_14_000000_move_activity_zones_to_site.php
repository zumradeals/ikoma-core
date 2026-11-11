<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zones du site réutilisées par les activités, étape Z3 (docs/ailct/V1-ZONES-DU-SITE.md) : reprise des données.
 * Décision du dirigeant : « toute zone devient une zone du site que les activités réutilisent ».
 *
 * - Chaque zone d'activité devient une zone du site ; les zones de MÊME NOM EXACT (y compris une zone du site déjà existante) sont fusionnées en une seule.
 * - Toutes les références sont redirigées vers la zone retenue : catégories, correspondances poste–zone, habilitations de participants et de titulaires.
 *   Les doublons créés par la fusion sont supprimés (même catégorie ou même poste, même zone).
 * - Politique d'une zone fusionnée : la plus restrictive (permanent autorisé seulement si toutes les politiques fusionnées l'autorisaient, entrée hors ligne idem ;
 *   une zone sans politique compte comme « refusé »). Les anciennes politiques d'activité (`zone_policies`) sont vidées : la politique est celle du site.
 * - Zone d'accueil : la zone retenue est d'accueil si l'une des zones fusionnées l'était.
 * - `list_revision` des activités concernées est incrémentée (les tablettes rechargent leur copie) ; un récapitulatif est écrit au journal d'audit.
 * Compte propriétaire de la base : les protections « ajout seul » sont suspendues table par table pendant la reprise puis rétablies dans la même transaction.
 * Non réversible (installation d'essai, aucune donnée réelle).
 */
return new class extends Migration
{
    /** Tables dont les lignes visent une zone et que la reprise réécrit. */
    private const REFERENCES = ['category_zone', 'checkpoint_zones', 'participation_entitlements', 'holder_entitlements'];

    public function up(): void
    {
        $others = DB::select("SELECT conrelid::regclass::text AS t FROM pg_constraint WHERE contype = 'f' AND confrelid = 'public.zones'::regclass AND array_length(conkey, 1) = 2 AND conrelid <> 'public.zone_policies'::regclass");
        if ($others !== []) {
            throw new RuntimeException('Reprise des zones interrompue : une clé composite vers les zones subsiste ('.$others[0]->t.'), revue du schéma requise.');
        }
        if (! DB::table('zones')->whereNotNull('event_id')->exists()) {
            return;
        }

        DB::statement(<<<'SQL'
CREATE TEMP TABLE zone_reprise ON COMMIT DROP AS
SELECT e.id AS old_id,
       COALESCE(
           (SELECT s.id FROM public.zones s WHERE s.event_id IS NULL AND s.site_id = e.site_id AND s.name = e.name),
           (SELECT f.id FROM public.zones f WHERE f.event_id IS NOT NULL AND f.site_id = e.site_id AND f.name = e.name ORDER BY f.created_at, f.id LIMIT 1)
       ) AS new_id,
       e.event_id
FROM public.zones e WHERE e.event_id IS NOT NULL
SQL);
        DB::statement(<<<'SQL'
CREATE TEMP TABLE zone_membres ON COMMIT DROP AS
SELECT old_id AS zone_id, new_id FROM zone_reprise
UNION
SELECT new_id, new_id FROM zone_reprise WHERE new_id IN (SELECT id FROM public.zones WHERE event_id IS NULL)
SQL);
        DB::statement(<<<'SQL'
CREATE TEMP TABLE zone_politiques ON COMMIT DROP AS
SELECT m.new_id AS zone_id,
       bool_and(coalesce(p.permanent_allowed, sp.permanent_allowed, false)) AS permanent_allowed,
       bool_and(coalesce(p.permanent_allowed, sp.permanent_allowed, false) AND coalesce(p.offline_entry_allowed, sp.offline_entry_allowed, false)) AS offline_entry_allowed,
       (array_agg(coalesce(p.classification, sp.classification) ORDER BY (m.zone_id = m.new_id) DESC, z.created_at, z.id) FILTER (WHERE coalesce(p.classification, sp.classification) IS NOT NULL))[1] AS classification
FROM zone_membres m
JOIN public.zones z ON z.id = m.zone_id
LEFT JOIN public.zone_policies p ON p.zone_id = m.zone_id
LEFT JOIN public.site_zone_policies sp ON sp.zone_id = m.zone_id
GROUP BY m.new_id
HAVING bool_or(p.zone_id IS NOT NULL OR sp.zone_id IS NOT NULL)
SQL);
        DB::statement(<<<'SQL'
CREATE TEMP TABLE zone_accueil ON COMMIT DROP AS
SELECT m.new_id AS zone_id, bool_or(z.est_accueil) AS est_accueil FROM zone_membres m JOIN public.zones z ON z.id = m.zone_id GROUP BY m.new_id
SQL);

        // Récapitulatif pour le journal d'audit, calculé avant toute modification.
        $summary = [
            'zones_reprises' => (int) DB::table('zone_reprise')->count(),
            'zones_du_site_obtenues' => (int) DB::table('zone_reprise')->distinct()->count('new_id'),
            'noms_fusionnes' => array_map(fn ($r) => $r->name, DB::select('SELECT z.name FROM zone_membres m JOIN public.zones z ON z.id = m.new_id GROUP BY z.name, m.new_id HAVING count(*) > 1 ORDER BY z.name')),
            'activites_concernees' => (int) DB::table('zone_reprise')->distinct()->count('event_id'),
        ];

        $events = DB::table('zone_reprise')->distinct()->pluck('event_id')->all();
        foreach (self::REFERENCES as $table) {
            DB::statement('ALTER TABLE public.'.$table.' DISABLE TRIGGER USER');
        }

        // Doublons créés par la fusion : on garde une seule ligne par catégorie (ou poste) et zone retenue.
        foreach (['category_zone' => 'category_id', 'checkpoint_zones' => 'checkpoint_id'] as $table => $owner) {
            DB::statement(<<<SQL
DELETE FROM public.{$table} c USING zone_reprise r1
WHERE c.zone_id = r1.old_id AND EXISTS (
    SELECT 1 FROM public.{$table} c2 LEFT JOIN zone_reprise r2 ON r2.old_id = c2.zone_id
    WHERE c2.{$owner} = c.{$owner} AND coalesce(r2.new_id, c2.zone_id) = r1.new_id AND c2.id <> c.id AND (r2.old_id IS NULL OR c2.id < c.id)
)
SQL);
        }
        foreach (self::REFERENCES as $table) {
            DB::statement('UPDATE public.'.$table.' t SET zone_id = r.new_id FROM zone_reprise r WHERE t.zone_id = r.old_id AND r.old_id <> r.new_id');
        }

        // Les anciennes politiques d'activité disparaissent (leur clé composite vise l'événement de la zone) ; zones fusionnées supprimées ; zones retenues passent au site.
        DB::statement('DELETE FROM public.zone_policies');
        DB::statement('DELETE FROM public.zones z USING zone_reprise r WHERE z.id = r.old_id AND r.old_id <> r.new_id');
        DB::statement('UPDATE public.zones z SET event_id = NULL, est_accueil = a.est_accueil, updated_at = now() FROM zone_accueil a WHERE z.id = a.zone_id');
        DB::statement(<<<'SQL'
INSERT INTO public.site_zone_policies (zone_id, classification, permanent_allowed, offline_entry_allowed, created_at, updated_at)
SELECT zone_id, classification, permanent_allowed, offline_entry_allowed, now(), now() FROM zone_politiques
ON CONFLICT (zone_id) DO UPDATE SET classification = EXCLUDED.classification, permanent_allowed = EXCLUDED.permanent_allowed,
    offline_entry_allowed = EXCLUDED.offline_entry_allowed, updated_at = now()
SQL);

        foreach (self::REFERENCES as $table) {
            DB::statement('ALTER TABLE public.'.$table.' ENABLE TRIGGER USER');
        }
        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('events')->whereIn('id', $chunk)->update(['list_revision' => DB::raw('list_revision + 1')]);
        }

        (new Audit)->append('zones.reprise_vers_site', null, $summary);
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : les zones des activités sont devenues des zones du site.');
    }
};
