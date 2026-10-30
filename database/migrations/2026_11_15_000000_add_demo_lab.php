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

SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_reset_demo_business(p_actor uuid, p_clear_accounts boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, public
AS $$
BEGIN
    -- Défense en profondeur, même si un appel SQL contourne le contrôleur web.
    IF NOT EXISTS (
        SELECT 1 FROM public.users u
        JOIN public.model_has_roles mr ON mr.model_id = u.id
            AND mr.model_type = concat('App', chr(92), 'Models', chr(92), 'User')
        JOIN public.roles r ON r.id = mr.role_id
        WHERE u.id = p_actor AND u.active IS TRUE
            AND u.two_factor_confirmed_at IS NOT NULL
            AND r.name = 'administrateur'
    ) THEN
        RAISE EXCEPTION 'Réinitialisation interdite pour cet utilisateur.'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    PERFORM pg_advisory_xact_lock(731903);
    -- SANS CASCADE : une future table dépendante doit obliger une revue du contrat.
    TRUNCATE TABLE
        public.sync_conflicts, public.sync_operations,
        public.notifications, public.alerts, public.access_events,
        public.identity_checks, public.photos, public.refusals,
        public.badge_assignments, public.group_members,
        public.badges, public.visitors, public.groups,
        public.badge_batches, public.devices, public.hosts,
        public.import_batches, public.category_zone,
        public.categories, public.zones, public.events,
        public.retired_badge_identifiers
    RESTART IDENTITY;
    -- Facultatif : ne garder que l'administrateur opérant la remise à zéro.
    -- Une base vierge reste utilisable sans repasser par l'assistant d'installation.
    IF p_clear_accounts THEN
        DELETE FROM public.maintenance_requests WHERE actor_id <> p_actor;
        DELETE FROM public.model_has_permissions
            WHERE model_type = concat('App', chr(92), 'Models', chr(92), 'User') AND model_id <> p_actor;
        DELETE FROM public.model_has_roles
            WHERE model_type = concat('App', chr(92), 'Models', chr(92), 'User') AND model_id <> p_actor;
        DELETE FROM public.sessions WHERE user_id IS NOT NULL AND user_id <> p_actor;
        DELETE FROM public.users WHERE id <> p_actor;
    END IF;
    -- Migrations, réglages et audit sont conservés quel que soit le choix.
END;
$$;
REVOKE ALL ON FUNCTION public.ikoma_reset_demo_business(uuid, boolean) FROM PUBLIC;

SQL);
        // Les fonctions sont toujours créées pour maintenir le schéma, mais jamais exposées
        // au compte web d'une installation productive.
        if (app()->environment('demo', 'local', 'testing')) {
            DB::statement('GRANT EXECUTE ON FUNCTION public.ikoma_reset_demo_event(uuid) TO ikoma_app');
            DB::statement('GRANT EXECUTE ON FUNCTION public.ikoma_reset_demo_business(uuid, boolean) TO ikoma_app');
        }
    }

    public function down(): void
    {
        // Le retour arrière pourrait faire perdre la provenance des données.
        throw new RuntimeException('Migration non réversible : conserver les marqueurs de démonstration.');
    }
};
