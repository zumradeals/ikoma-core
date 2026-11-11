<?php

use App\Services\LegacyCardRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retrait du registre technique des cartes, étape 4 (décision du dirigeant : l'ancien registre est effacé, installation d'essai sans donnée réelle).
 * - les cartes existantes sont rattachées au site et tout le contenu de l'ancien événement protégé est effacé (LegacyCardRegistry) ;
 * - plus de contexte permanent : protections, nature « permanent », index et colonne `events.is_permanent` disparaissent ;
 * - `holder_cards.event_id` et sa clé composite disparaissent (la fiche carte pointe directement le badge) ; son garde-fou ne compare plus que le titulaire et le badge.
 * Non réversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registry = DB::table('events')->where('is_permanent', true)->value('id');
        if ($registry !== null) {
            (new LegacyCardRegistry(DB::connection()))->detach((string) $registry);
        }

        DB::unprepared('DROP TRIGGER IF EXISTS permanent_event_truncate ON events; DROP TRIGGER IF EXISTS permanent_event_delete ON events; DROP TRIGGER IF EXISTS permanent_event_update ON events;');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_permanent_event_guard();');
        DB::unprepared('DROP TRIGGER IF EXISTS events_kind_sync ON events; DROP FUNCTION IF EXISTS public.ikoma_event_kind();');
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_kind_valid');
        DB::statement("UPDATE events SET kind = 'evenement' WHERE kind = 'permanent'");
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_kind_valid CHECK (kind IN ('evenement', 'stage', 'visite'))");
        DB::statement('DROP INDEX IF EXISTS events_single_permanent');
        DB::statement('ALTER TABLE events DROP COLUMN is_permanent');

        // Même garde-fou qu'avant (cartes, tirages, habilitations), sans la comparaison de l'événement de la fiche carte.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_holder_card_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        -- Une table vide peut être vidée (remise à zéro de démonstration) ; jamais une table qui contient des cartes ou des habilitations.
        IF EXISTS (SELECT 1 FROM public.holder_cards) OR EXISTS (SELECT 1 FROM public.holder_card_prints) OR EXISTS (SELECT 1 FROM public.holder_entitlements) THEN
            RAISE EXCEPTION 'Les cartes, habilitations et historiques de titulaires ne se remettent pas à zéro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NULL;
    END IF;
    IF TG_TABLE_NAME = 'holder_card_prints' THEN
        RAISE EXCEPTION 'L''historique des tirages de cartes est en ajout seul.' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Une carte ou une habilitation ne se supprime pas.' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_TABLE_NAME = 'holder_entitlements' THEN
        IF NEW.holder_id IS DISTINCT FROM OLD.holder_id OR NEW.event_id IS DISTINCT FROM OLD.event_id OR NEW.zone_id IS DISTINCT FROM OLD.zone_id
           OR NEW.valid_from IS DISTINCT FROM OLD.valid_from OR NEW.valid_until IS DISTINCT FROM OLD.valid_until OR NEW.granted_by IS DISTINCT FROM OLD.granted_by THEN
            RAISE EXCEPTION 'Une habilitation ne se modifie pas : elle se retire, puis une nouvelle est accordée.' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.revoked_at IS NOT NULL THEN
            RAISE EXCEPTION 'Une habilitation retirée ne se modifie plus.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.holder_id IS DISTINCT FROM OLD.holder_id OR NEW.badge_id IS DISTINCT FROM OLD.badge_id OR NEW.rank IS DISTINCT FROM OLD.rank THEN
        RAISE EXCEPTION 'Une carte reste attachée à son titulaire et à son badge.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status NOT IN ('active', 'retiree') THEN
        -- Statut terminal : aucune réactivation, aucun changement de motif ; seul le lien vers la carte de remplacement peut être renseigné, une fois.
        IF NEW.status IS DISTINCT FROM OLD.status OR NEW.revoked_at IS DISTINCT FROM OLD.revoked_at OR NEW.revoked_by IS DISTINCT FROM OLD.revoked_by
           OR NEW.revocation_reason IS DISTINCT FROM OLD.revocation_reason OR NEW.revocation_note IS DISTINCT FROM OLD.revocation_note
           OR (OLD.replaced_by_card_id IS NOT NULL AND NEW.replaced_by_card_id IS DISTINCT FROM OLD.replaced_by_card_id) THEN
            RAISE EXCEPTION 'Une carte révoquée, perdue ou volée ne se réactive pas et ne se modifie plus.' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('ALTER TABLE holder_cards DROP CONSTRAINT IF EXISTS holder_cards_badge_id_event_id_foreign');
        DB::statement('ALTER TABLE holder_cards DROP COLUMN event_id');
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : l\'ancien registre technique des cartes est effacé.');
    }
};
