<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * V1 / PR 1 — Socle du site : site, postes permanents, affectation des tablettes, relation poste–zone.
 * Contrat : docs/ailct/V1-INVARIANTS-SITE-ET-CONTROLE.md. Migration additive : rien n'est supprimé hors la colonne devenue redondante `checkpoint_zones.device_id`.
 *
 * - sites : le site de l'Académie (un seul exploité en V1, modélisé pour plus). Il possède ses zones (zones.site_id) et ses postes.
 * - checkpoints : les postes de contrôle, objets PERMANENTS (désactivables, jamais supprimés ; code immuable). Capacités et sens obligatoire sont des DONNÉES
 *   (catalogue : accueil, scan, sortie). Valeurs par défaut = contrat V1 antérieur (S02) : poste_2 accueil+scan+sortie, soute scan avec sens obligatoire.
 * - devices.checkpoint_id : poste auquel la tablette est affectée (plusieurs tablettes par poste). `devices.point_code` reste le miroir hérité, synchronisé par déclencheur
 *   dans les deux sens, si bien que le moteur de contrôle et tout écrit existant continuent de fonctionner. L'ancien point « hotel » = tablette sans poste.
 * - device_assignments : historique d'affectation de chaque tablette (une seule affectation ouverte par tablette, garantie par la base).
 * - checkpoint_zones : la correspondance poste–zone est portée par le POSTE (checkpoint_id) et non plus par une tablette.
 */
return new class extends Migration
{
    public function up(): void
    {
        $siteId = (string) Str::uuid();
        Schema::create('sites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->timestampsTz();
        });
        DB::table('sites')->insert(['id' => $siteId, 'name' => 'Site de l’Académie', 'created_at' => now(), 'updated_at' => now()]);

        // Zones du site (leur rattachement à une activité disparaît dans un lot ultérieur).
        Schema::table('zones', fn (Blueprint $t) => $t->uuid('site_id')->nullable());
        DB::table('zones')->update(['site_id' => $siteId]);
        DB::statement('ALTER TABLE zones ALTER COLUMN site_id SET NOT NULL');
        DB::statement('ALTER TABLE zones ADD CONSTRAINT zones_site_id_foreign FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_zone_default_site() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.site_id IS NULL THEN
        NEW.site_id := (SELECT id FROM public.sites ORDER BY created_at, id LIMIT 1);
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER zones_default_site BEFORE INSERT ON zones FOR EACH ROW EXECUTE FUNCTION public.ikoma_zone_default_site();
SQL);

        // Postes de contrôle.
        Schema::create('checkpoints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('site_id');
            $t->string('code', 20)->unique();
            $t->string('name', 120);
            $t->string('function_label', 160)->nullable();
            $t->jsonb('capabilities')->default('[]');
            $t->boolean('direction_required')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('site_id')->references('id')->on('sites')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE checkpoints ADD CONSTRAINT checkpoints_code_valid CHECK (code ~ '^[a-z][a-z0-9_]{1,19}$')");
        DB::statement("ALTER TABLE checkpoints ADD CONSTRAINT checkpoints_capabilities_valid CHECK (jsonb_typeof(capabilities) = 'array' AND capabilities <@ '[\"accueil\",\"scan\",\"sortie\"]'::jsonb)");
        DB::statement("ALTER TABLE checkpoints ADD CONSTRAINT checkpoints_direction_needs_scan CHECK (NOT direction_required OR jsonb_exists(capabilities, 'scan'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_checkpoint_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Un poste ne se supprime pas : il se désactive.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.code IS DISTINCT FROM OLD.code OR NEW.site_id IS DISTINCT FROM OLD.site_id THEN
        RAISE EXCEPTION 'Le code et le site d''un poste sont immuables.' USING ERRCODE = 'check_violation';
    END IF;
    NEW.version := OLD.version + 1;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER checkpoints_guard BEFORE UPDATE OR DELETE ON checkpoints FOR EACH ROW EXECUTE FUNCTION public.ikoma_checkpoint_guard();
SQL);
        foreach ([
            ['poste_1', 'Poste 1', 'Entrée principale', ['scan'], false], // lot A : les cinq postes scannent (reprise des installations existantes : migration 2026_12_17_000000)
            ['poste_2', 'Poste 2', 'Accès Académie', ['accueil', 'scan', 'sortie'], false],
            ['poste_3', 'Poste 3', 'Chef du village', ['scan'], false],
            ['poste_4', 'Poste 4', 'Le Porton', ['scan'], false],
            ['soute', 'Point 5', 'La Soute', ['scan'], true],
        ] as [$code, $name, $label, $capabilities, $direction]) {
            DB::table('checkpoints')->insert(['id' => (string) Str::uuid(), 'site_id' => $siteId, 'code' => $code, 'name' => $name, 'function_label' => $label,
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'direction_required' => $direction, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Tablettes : poste d'affectation, synchronisé avec le code de point hérité.
        Schema::table('devices', fn (Blueprint $t) => $t->uuid('checkpoint_id')->nullable());
        DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_checkpoint_id_foreign FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE RESTRICT');
        DB::statement('UPDATE devices d SET checkpoint_id = c.id FROM checkpoints c WHERE c.code = d.point_code');
        DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_point_code_valid');

        Schema::create('device_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('device_id');
            $t->uuid('checkpoint_id')->nullable();
            $t->timestampTz('assigned_at');
            $t->timestampTz('ended_at')->nullable();
            $t->uuid('assigned_by')->nullable();
            $t->string('reason', 200)->nullable();
            $t->timestampsTz();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('checkpoint_id')->references('id')->on('checkpoints')->restrictOnDelete();
            $t->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['checkpoint_id', 'ended_at']);
        });
        DB::statement('CREATE UNIQUE INDEX device_assignments_one_open ON device_assignments (device_id) WHERE ended_at IS NULL');
        DB::statement('ALTER TABLE device_assignments ADD CONSTRAINT device_assignments_period CHECK (ended_at IS NULL OR ended_at >= assigned_at)');
        DB::statement('INSERT INTO device_assignments (id, device_id, checkpoint_id, assigned_at, created_at, updated_at) SELECT gen_random_uuid(), id, checkpoint_id, coalesce(created_at, now()), now(), now() FROM devices');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_device_checkpoint_sync() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c record; mode text := 'none';
BEGIN
    IF TG_OP = 'INSERT' THEN
        mode := CASE WHEN NEW.checkpoint_id IS NOT NULL THEN 'by_id' ELSE 'by_code' END;
    ELSIF NEW.checkpoint_id IS DISTINCT FROM OLD.checkpoint_id AND NEW.point_code IS NOT DISTINCT FROM OLD.point_code THEN
        mode := 'by_id';
    ELSIF NEW.point_code IS DISTINCT FROM OLD.point_code THEN
        mode := 'by_code';
    END IF;
    IF mode = 'by_id' THEN
        IF NEW.checkpoint_id IS NULL THEN
            NEW.point_code := NULL;
        ELSE
            SELECT * INTO c FROM public.checkpoints WHERE id = NEW.checkpoint_id;
            IF NOT FOUND THEN RAISE EXCEPTION 'Poste inconnu.' USING ERRCODE = 'check_violation'; END IF;
            IF NOT c.active THEN RAISE EXCEPTION 'Ce poste est désactivé : aucune tablette ne peut lui être affectée.' USING ERRCODE = 'check_violation'; END IF;
            NEW.point_code := c.code;
        END IF;
    ELSIF mode = 'by_code' THEN
        IF NEW.point_code IS NULL OR NEW.point_code = 'hotel' THEN
            NEW.checkpoint_id := NULL;
        ELSE
            SELECT * INTO c FROM public.checkpoints WHERE code = NEW.point_code;
            IF NOT FOUND THEN RAISE EXCEPTION 'Point de contrôle inconnu.' USING ERRCODE = 'check_violation'; END IF;
            IF NOT c.active THEN RAISE EXCEPTION 'Ce poste est désactivé : aucune tablette ne peut lui être affectée.' USING ERRCODE = 'check_violation'; END IF;
            NEW.checkpoint_id := c.id;
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER devices_checkpoint_sync BEFORE INSERT OR UPDATE ON devices FOR EACH ROW EXECUTE FUNCTION public.ikoma_device_checkpoint_sync();

CREATE OR REPLACE FUNCTION public.ikoma_device_assignment_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.checkpoint_id IS NOT DISTINCT FROM OLD.checkpoint_id THEN
            RETURN NULL;
        END IF;
        UPDATE public.device_assignments SET ended_at = now(), updated_at = now() WHERE device_id = NEW.id AND ended_at IS NULL;
    END IF;
    INSERT INTO public.device_assignments (id, device_id, checkpoint_id, assigned_at, created_at, updated_at) VALUES (gen_random_uuid(), NEW.id, NEW.checkpoint_id, now(), now(), now());
    RETURN NULL;
END $$;
CREATE TRIGGER devices_assignment_history AFTER INSERT OR UPDATE ON devices FOR EACH ROW EXECUTE FUNCTION public.ikoma_device_assignment_history();
SQL);

        // Relation poste–zone : portée par le poste.
        Schema::table('checkpoint_zones', fn (Blueprint $t) => $t->uuid('checkpoint_id')->nullable());
        DB::statement('UPDATE checkpoint_zones z SET checkpoint_id = d.checkpoint_id FROM devices d WHERE d.id = z.device_id');
        if (DB::table('checkpoint_zones')->whereNull('checkpoint_id')->exists()) {
            throw new RuntimeException('Des correspondances point–zone visent une tablette sans poste : revue du contrat requise avant migration.');
        }
        DB::statement('DELETE FROM checkpoint_zones a USING checkpoint_zones b WHERE a.checkpoint_id = b.checkpoint_id AND a.zone_id = b.zone_id AND a.id > b.id');
        Schema::table('checkpoint_zones', fn (Blueprint $t) => $t->dropColumn('device_id'));
        DB::statement('ALTER TABLE checkpoint_zones ALTER COLUMN checkpoint_id SET NOT NULL');
        DB::statement('ALTER TABLE checkpoint_zones ADD CONSTRAINT checkpoint_zones_checkpoint_id_foreign FOREIGN KEY (checkpoint_id) REFERENCES checkpoints(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX checkpoint_zones_checkpoint_zone_unique ON checkpoint_zones (checkpoint_id, zone_id)');

        // Remise à zéro de démonstration : l'historique d'affectation part avec les tablettes (jamais les postes ni le site).
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.devices,', 'public.device_assignments, public.devices,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : le site, les postes et l’historique d’affectation sont conservés.');
    }
};
