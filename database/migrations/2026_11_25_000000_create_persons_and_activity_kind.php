<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S03/S04 — Identité unique, activités indépendantes, participations persistantes.
 *
 * - persons : identité durable, indépendante de toute activité. Aucune unicité sur le nom : deux personnes de même nom restent deux personnes tant qu'un humain ne
 *   décide pas autrement (jamais de fusion automatique sur la seule correspondance du nom).
 * - events.kind : nature de l'activité (evenement, stage, visite) ; « permanent » est réservé au contexte permanent et synchronisé avec events.is_permanent.
 * - visitors (= la PARTICIPATION d'une personne à une activité) reçoit person_id (obligatoire) et les champs de clôture individuelle. Une personne a au plus une
 *   participation par activité. Un déclencheur crée une personne neuve pour toute participation insérée sans personne : aucune écriture ne peut contourner l'identité.
 * - permanent_holders (profil d'engagement permanent : carte, identifiant imprimé) reçoit person_id : un titulaire permanent EST une personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('full_name', 160);
            $t->string('organisation', 160)->nullable();
            $t->string('status', 10)->default('actif');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_status_valid CHECK (status IN ('actif', 'archive'))");
        DB::statement('ALTER TABLE persons ADD CONSTRAINT persons_split_name_together CHECK ((first_name IS NULL) = (last_name IS NULL))');
        DB::statement('CREATE INDEX persons_name_trgm ON persons USING gin (public.ikoma_normalize(full_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX persons_name_idx ON persons (public.ikoma_normalize(full_name))');

        // Nature de l'activité.
        Schema::table('events', fn (Blueprint $t) => $t->string('kind', 12)->default('evenement'));
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_kind_valid CHECK (kind IN ('evenement', 'stage', 'visite', 'permanent'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_event_kind() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.is_permanent THEN
        NEW.kind := 'permanent';
    ELSIF NEW.kind = 'permanent' THEN
        RAISE EXCEPTION 'La nature « permanent » est réservée au contexte permanent.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER events_kind_sync BEFORE INSERT OR UPDATE ON events FOR EACH ROW EXECUTE FUNCTION public.ikoma_event_kind();
SQL);
        DB::statement("UPDATE events SET kind = 'permanent' WHERE is_permanent");

        // Colonnes de liaison, remplies avant d'être rendues obligatoires.
        Schema::table('visitors', function (Blueprint $t) {
            $t->uuid('person_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->uuid('closed_by')->nullable();
            $t->string('closure_reason', 200)->nullable();
        });
        Schema::table('permanent_holders', fn (Blueprint $t) => $t->uuid('person_id')->nullable());

        // Reprise éventuelle de lignes existantes (base de développement) : une personne par ligne, jamais de rapprochement automatique.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE r record; pid uuid;
BEGIN
    ALTER TABLE public.permanent_holders DISABLE TRIGGER USER;
    FOR r IN SELECT id, full_name, first_name, last_name, organisation FROM public.permanent_holders WHERE person_id IS NULL LOOP
        pid := gen_random_uuid();
        INSERT INTO public.persons (id, first_name, last_name, full_name, organisation, created_at, updated_at) VALUES (pid, r.first_name, r.last_name, r.full_name, r.organisation, now(), now());
        UPDATE public.permanent_holders SET person_id = pid WHERE id = r.id;
    END LOOP;
    ALTER TABLE public.permanent_holders ENABLE TRIGGER USER;
    FOR r IN SELECT id, full_name, organisation, holder_id FROM public.visitors WHERE person_id IS NULL LOOP
        pid := NULL;
        IF r.holder_id IS NOT NULL THEN
            SELECT person_id INTO pid FROM public.permanent_holders WHERE id = r.holder_id;
        END IF;
        IF pid IS NULL THEN
            pid := gen_random_uuid();
            INSERT INTO public.persons (id, full_name, organisation, created_at, updated_at) VALUES (pid, r.full_name, r.organisation, now(), now());
        END IF;
        UPDATE public.visitors SET person_id = pid WHERE id = r.id;
    END LOOP;
END $$;
SQL);

        DB::statement('ALTER TABLE visitors ALTER COLUMN person_id SET NOT NULL');
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_person_id_foreign FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_closure_coherent CHECK ((closed_at IS NULL) = (closure_reason IS NULL))');
        DB::statement('CREATE UNIQUE INDEX visitors_person_event_unique ON visitors (person_id, event_id)');
        DB::statement('CREATE INDEX visitors_person_idx ON visitors (person_id)');
        DB::statement('ALTER TABLE permanent_holders ALTER COLUMN person_id SET NOT NULL');
        DB::statement('ALTER TABLE permanent_holders ADD CONSTRAINT permanent_holders_person_id_foreign FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX permanent_holders_person_unique ON permanent_holders (person_id)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_holder_person() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pid uuid;
BEGIN
    IF NEW.person_id IS NULL THEN
        pid := gen_random_uuid();
        INSERT INTO public.persons (id, first_name, last_name, full_name, organisation, created_at, updated_at) VALUES (pid, NEW.first_name, NEW.last_name, NEW.full_name, NEW.organisation, now(), now());
        NEW.person_id := pid;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER permanent_holders_ensure_person BEFORE INSERT ON permanent_holders FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_person();

CREATE OR REPLACE FUNCTION public.ikoma_visitor_person() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pid uuid;
BEGIN
    IF NEW.person_id IS NULL THEN
        IF NEW.holder_id IS NOT NULL THEN
            SELECT person_id INTO pid FROM public.permanent_holders WHERE id = NEW.holder_id;
        END IF;
        IF pid IS NULL THEN
            pid := gen_random_uuid();
            INSERT INTO public.persons (id, full_name, organisation, created_at, updated_at) VALUES (pid, NEW.full_name, NEW.organisation, now(), now());
        END IF;
        NEW.person_id := pid;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER visitors_ensure_person BEFORE INSERT ON visitors FOR EACH ROW EXECUTE FUNCTION public.ikoma_visitor_person();

CREATE OR REPLACE FUNCTION public.ikoma_person_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.person_id IS DISTINCT FROM OLD.person_id THEN
        RAISE EXCEPTION 'Une participation ou un profil permanent ne change jamais de personne.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER visitors_person_immutable BEFORE UPDATE ON visitors FOR EACH ROW EXECUTE FUNCTION public.ikoma_person_immutable();
CREATE TRIGGER permanent_holders_person_immutable BEFORE UPDATE ON permanent_holders FOR EACH ROW EXECUTE FUNCTION public.ikoma_person_immutable();
SQL);

        // La remise à zéro de démonstration efface aussi les personnes (toutes les tables qui les référencent figurent dans la même instruction).
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $updated = str_replace('public.permanent_holders,', 'public.persons, public.permanent_holders,', $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro inattendue : revue du contrat requise.');
        }
        DB::unprepared($updated);
    }

    public function down(): void
    {
        if (DB::table('persons')->exists()) {
            throw new RuntimeException('Migration non réversible : des personnes existent.');
        }
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.persons, public.permanent_holders,', 'public.permanent_holders,', $definition));
        DB::unprepared('DROP TRIGGER IF EXISTS visitors_person_immutable ON visitors; DROP TRIGGER IF EXISTS permanent_holders_person_immutable ON permanent_holders; DROP TRIGGER IF EXISTS visitors_ensure_person ON visitors; DROP TRIGGER IF EXISTS permanent_holders_ensure_person ON permanent_holders; DROP TRIGGER IF EXISTS events_kind_sync ON events;');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_person_immutable(); DROP FUNCTION IF EXISTS public.ikoma_visitor_person(); DROP FUNCTION IF EXISTS public.ikoma_holder_person(); DROP FUNCTION IF EXISTS public.ikoma_event_kind();');
        DB::statement('DROP INDEX IF EXISTS permanent_holders_person_unique');
        Schema::table('permanent_holders', function (Blueprint $t) {
            $t->dropForeign('permanent_holders_person_id_foreign');
            $t->dropColumn('person_id');
        });
        DB::statement('DROP INDEX IF EXISTS visitors_person_idx');
        DB::statement('DROP INDEX IF EXISTS visitors_person_event_unique');
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT IF EXISTS visitors_closure_coherent, DROP CONSTRAINT IF EXISTS visitors_closed_by_foreign, DROP CONSTRAINT IF EXISTS visitors_person_id_foreign');
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn(['person_id', 'closed_at', 'closed_by', 'closure_reason']));
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_kind_valid');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::dropIfExists('persons');
    }
};
