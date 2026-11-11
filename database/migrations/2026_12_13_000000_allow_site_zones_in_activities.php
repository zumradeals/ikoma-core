<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zones du site réutilisées par les activités, étape Z1 (docs/ailct/V1-ZONES-DU-SITE.md) : schéma souple, aucun comportement existant ne change.
 * Les clés composites (zone, événement) de `category_zone`, `checkpoint_zones`, `participation_entitlements` et `holder_entitlements` interdisaient de viser
 * une zone du site (sans événement). Elles sont remplacées par une clé simple vers `zones(id)` et un déclencheur : la zone visée est soit du même événement,
 * soit une zone du site. Les zones d'activité existantes et leurs références restent valides.
 */
return new class extends Migration
{
    private const TABLES = ['category_zone', 'checkpoint_zones', 'participation_entitlements', 'holder_entitlements'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_zone_of_event_or_site() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM public.zones z WHERE z.id = NEW.zone_id AND (z.event_id IS NULL OR z.event_id = NEW.event_id)) THEN
        RAISE EXCEPTION 'Une activité ne vise qu''une zone du site ou une zone de sa propre activité.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
SQL);
        foreach (self::TABLES as $table) {
            $names = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'f' AND confrelid = 'public.zones'::regclass AND array_length(conkey, 1) = 2", ['public.'.$table]);
            foreach ($names as $row) {
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.$row->conname.'"');
            }
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$table.'_zone_id_simple_foreign FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE RESTRICT');
            DB::unprepared('CREATE TRIGGER '.$table.'_zone_of_event_or_site BEFORE INSERT OR UPDATE OF zone_id, event_id ON '.$table.' FOR EACH ROW EXECUTE FUNCTION public.ikoma_zone_of_event_or_site();');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : des activités peuvent déjà viser des zones du site.');
    }
};
