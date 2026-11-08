<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S05 — Un badge physique n'appartient plus à une activité : il circule.
 *
 * Avant : badge_assignments, access_events et badges.badge_batch_id référençaient badges/badge_batches par une clé étrangère COMPOSITE (id, event_id), ce qui figeait
 * à vie l'activité d'un badge. Désormais :
 * - ces liens sont des clés étrangères simples (le lot et l'activité d'émission restent des données de provenance) ;
 * - badges.event_id devient « l'activité de rattachement COURANTE » : elle ne change que par une mise à disposition explicite (BadgeRegistry::transfer),
 *   jamais tant que le badge est attribué, et chaque changement est conservé dans badge_transfers (historique) ;
 * - l'attribution (badge_assignments) garde son propre event_id : l'historique d'un badge traverse les activités.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE badge_assignments DROP CONSTRAINT badge_assignments_badge_id_event_id_foreign');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_badge_id_event_id_foreign');
        DB::statement('ALTER TABLE badges DROP CONSTRAINT badges_badge_batch_id_event_id_foreign');
        DB::statement('ALTER TABLE badge_assignments ADD CONSTRAINT badge_assignments_badge_id_foreign FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_badge_id_foreign FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_badge_batch_id_foreign FOREIGN KEY (badge_batch_id) REFERENCES badge_batches(id) ON DELETE RESTRICT');

        Schema::create('badge_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('badge_id');
            $t->uuid('from_event_id');
            $t->uuid('to_event_id');
            $t->uuid('actor_id');
            $t->boolean('reservation_released')->default(false);
            $t->timestampsTz();
            $t->foreign('badge_id')->references('id')->on('badges')->restrictOnDelete();
            $t->foreign('from_event_id')->references('id')->on('events')->restrictOnDelete();
            $t->foreign('to_event_id')->references('id')->on('events')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['badge_id', 'created_at']);
        });
        DB::statement('ALTER TABLE badge_transfers ADD CONSTRAINT badge_transfers_distinct CHECK (from_event_id <> to_event_id)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_transfer_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'L''historique des mises à disposition de badges ne se modifie pas.' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_transfers_immutable BEFORE UPDATE ON badge_transfers FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_transfer_immutable();

-- Le rattachement ne change jamais tant que le badge est attribué (ou n'est pas Disponible), et seulement s'il n'est ni carte permanente ni imprimé pour une personne.
CREATE OR REPLACE FUNCTION public.ikoma_badge_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_id IS DISTINCT FROM OLD.event_id THEN
        IF OLD.status <> 'Disponible' OR EXISTS (SELECT 1 FROM public.badge_assignments a WHERE a.badge_id = OLD.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL) THEN
            RAISE EXCEPTION 'Un badge attribué ou non disponible ne change pas d''activité.' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER badges_event_guard BEFORE UPDATE OF event_id ON badges FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_event_guard();
SQL);

        // Remise à zéro de démonstration : l'historique des mises à disposition et les attributions faites dans une autre activité partent avec les badges.
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $business = str_replace('public.badge_assignments, public.group_members,', 'public.badge_transfers, public.badge_assignments, public.group_members,', $business, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (globale) inattendue : revue du contrat requise.');
        }
        DB::unprepared($business);

        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        $event = str_replace(
            'DELETE FROM public.badge_assignments WHERE event_id = p_event;',
            "DELETE FROM public.badge_transfers WHERE from_event_id = p_event OR to_event_id = p_event OR badge_id IN (SELECT id FROM public.badges WHERE event_id = p_event);\n    DELETE FROM public.access_events WHERE badge_id IN (SELECT id FROM public.badges WHERE event_id = p_event);\n    DELETE FROM public.badge_assignments WHERE event_id = p_event OR badge_id IN (SELECT id FROM public.badges WHERE event_id = p_event);",
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
        if (DB::table('badge_transfers')->exists()) {
            throw new RuntimeException('Migration non réversible : des mises à disposition existent.');
        }
        $business = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.badge_transfers, public.badge_assignments,', 'public.badge_assignments,', $business));
        DB::unprepared('DROP TRIGGER IF EXISTS badges_event_guard ON badges; DROP FUNCTION IF EXISTS public.ikoma_badge_event_guard(); DROP TRIGGER IF EXISTS badge_transfers_immutable ON badge_transfers; DROP FUNCTION IF EXISTS public.ikoma_badge_transfer_immutable();');
        Schema::dropIfExists('badge_transfers');
        DB::statement('ALTER TABLE badges DROP CONSTRAINT badges_badge_batch_id_foreign');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_badge_id_foreign');
        DB::statement('ALTER TABLE badge_assignments DROP CONSTRAINT badge_assignments_badge_id_foreign');
        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_badge_batch_id_event_id_foreign FOREIGN KEY (badge_batch_id, event_id) REFERENCES badge_batches(id, event_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_badge_id_event_id_foreign FOREIGN KEY (badge_id, event_id) REFERENCES badges(id, event_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE badge_assignments ADD CONSTRAINT badge_assignments_badge_id_event_id_foreign FOREIGN KEY (badge_id, event_id) REFERENCES badges(id, event_id) ON DELETE RESTRICT');
    }
};
