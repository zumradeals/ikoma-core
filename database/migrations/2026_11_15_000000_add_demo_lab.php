<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les scénarios de laboratoire sont marqués en base, jamais devinés d'après un nom.
 * Une fonction SQL bornée autorise uniquement la réinitialisation de CES événements,
 * sans accorder DELETE sur les journaux d'accès au compte web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
            $table->string('demo_scenario', 24)->nullable();
            $table->foreignUuid('demo_created_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_reset_demo_event(p_event uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
    -- Pas de purge globale, ni d'effacement d'événement non marqué.
    PERFORM pg_advisory_xact_lock(731903);
    LOCK TABLE public.events, public.hosts, public.devices, public.visitors,
        public.badge_batches, public.badges, public.badge_assignments,
        public.access_events, public.identity_checks, public.photos,
        public.sync_operations, public.sync_conflicts, public.notifications,
        public.alerts, public.refusals, public.import_batches, public.groups,
        public.group_members, public.categories, public.zones,
        public.category_zone IN EXCLUSIVE MODE;

    IF NOT EXISTS (SELECT 1 FROM public.events WHERE id = p_event AND is_demo IS TRUE) THEN
        RAISE EXCEPTION 'Réinitialisation interdite : événement non marqué comme démonstration.'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    DELETE FROM public.sync_conflicts WHERE event_id = p_event;
    DELETE FROM public.sync_operations
        WHERE device_id IN (SELECT id FROM public.devices WHERE event_id = p_event)
           OR visitor_id IN (SELECT id FROM public.visitors WHERE event_id = p_event);
    DELETE FROM public.notifications WHERE event_id = p_event;
    DELETE FROM public.alerts WHERE event_id = p_event;
    DELETE FROM public.access_events WHERE event_id = p_event;
    DELETE FROM public.identity_checks
        WHERE visitor_id IN (SELECT id FROM public.visitors WHERE event_id = p_event);
    DELETE FROM public.photos
        WHERE visitor_id IN (SELECT id FROM public.visitors WHERE event_id = p_event);
    DELETE FROM public.refusals
        WHERE visitor_id IN (SELECT id FROM public.visitors WHERE event_id = p_event);
    DELETE FROM public.badge_assignments WHERE event_id = p_event;
    DELETE FROM public.group_members
        WHERE group_id IN (SELECT id FROM public.groups WHERE event_id = p_event);
    DELETE FROM public.badges WHERE event_id = p_event;
    DELETE FROM public.visitors WHERE event_id = p_event;
    DELETE FROM public.groups WHERE event_id = p_event;
    DELETE FROM public.badge_batches WHERE event_id = p_event;
    DELETE FROM public.devices WHERE event_id = p_event;
    DELETE FROM public.hosts WHERE event_id = p_event;
    DELETE FROM public.import_batches WHERE event_id = p_event;
    DELETE FROM public.category_zone WHERE event_id = p_event;
    DELETE FROM public.categories WHERE event_id = p_event;
    DELETE FROM public.zones WHERE event_id = p_event;
    DELETE FROM public.events WHERE id = p_event;
END;
$$;
REVOKE ALL ON FUNCTION public.ikoma_reset_demo_event(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.ikoma_reset_demo_event(uuid) TO ikoma_app;
SQL);
    }

    public function down(): void
    {
        // Le retour arrière pourrait faire perdre la provenance des données.
        throw new RuntimeException('Migration non réversible : conserver les marqueurs de démonstration.');
    }
};
