<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retrait du registre technique des cartes, étape 1 (schéma additif, rien n'est encore utilisé) : un badge peut appartenir au SITE, sans événement.
 * - badges.event_id et badges.badge_batch_id facultatifs ; un badge du site n'a ni lot, ni catégorie, ni personne imprimée, ni réservation (contrainte) ;
 * - holder_cards.event_id facultatif, clé simple vers le badge (la clé composite ne protège plus rien quand l'événement est vide) ;
 * - badge_issuances.event_id et badge_print_runs.event_id facultatifs ;
 * - retired_badge_identifiers : la clé primaire (événement, code) devient deux index uniques (avec événement / site) et le garde-fou compare avec IS NOT DISTINCT FROM ;
 * - garde du rattachement : un badge du site ne devient jamais un badge d'activité, et inversement.
 * Les lignes existantes ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE badges ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('ALTER TABLE badges ALTER COLUMN badge_batch_id DROP NOT NULL');
        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_site_shape CHECK (event_id IS NOT NULL OR (badge_batch_id IS NULL AND category_id IS NULL AND printed_for_visitor_id IS NULL AND reserved_device_id IS NULL AND reserved_group_id IS NULL))');

        DB::statement('ALTER TABLE holder_cards ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_badge_id_simple_foreign FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE RESTRICT');

        DB::statement('ALTER TABLE badge_issuances ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('ALTER TABLE badge_print_runs ALTER COLUMN event_id DROP NOT NULL');

        DB::statement('ALTER TABLE retired_badge_identifiers DROP CONSTRAINT retired_badge_identifiers_pkey');
        DB::statement('ALTER TABLE retired_badge_identifiers ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX retired_badge_identifiers_event_code ON retired_badge_identifiers (event_id, code) WHERE event_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX retired_badge_identifiers_site_code ON retired_badge_identifiers (code) WHERE event_id IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_retired_badge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        INSERT INTO retired_badge_identifiers(event_id, code, token_hash) VALUES (OLD.event_id, OLD.code, encode(sha256(OLD.qr_token::bytea), 'hex'));
        RETURN OLD;
    END IF;
    IF EXISTS (SELECT 1 FROM retired_badge_identifiers WHERE (event_id IS NOT DISTINCT FROM NEW.event_id AND code = NEW.code) OR token_hash = encode(sha256(NEW.qr_token::bytea), 'hex')) THEN
        RAISE EXCEPTION 'Identifiant de badge retiré non réattribuable.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION public.ikoma_badge_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_id IS DISTINCT FROM OLD.event_id THEN
        IF OLD.event_id IS NULL OR NEW.event_id IS NULL THEN
            RAISE EXCEPTION 'Un badge du site ne devient pas un badge d''activité, ni l''inverse.' USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.status <> 'Disponible' OR EXISTS (SELECT 1 FROM public.badge_assignments a WHERE a.badge_id = OLD.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL) THEN
            RAISE EXCEPTION 'Un badge attribué ou non disponible ne change pas d''activité.' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::table('badges')->whereNull('event_id')->exists() || DB::table('retired_badge_identifiers')->whereNull('event_id')->exists()) {
            throw new RuntimeException('Retour destructif interdit : des badges du site existent.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_id IS DISTINCT FROM OLD.event_id THEN
        IF OLD.status <> 'Disponible' OR EXISTS (SELECT 1 FROM public.badge_assignments a WHERE a.badge_id = OLD.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL) THEN
            RAISE EXCEPTION 'Un badge attribué ou non disponible ne change pas d''activité.' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION public.ikoma_retired_badge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        INSERT INTO retired_badge_identifiers(event_id, code, token_hash) VALUES (OLD.event_id, OLD.code, encode(sha256(OLD.qr_token::bytea), 'hex'));
        RETURN OLD;
    END IF;
    IF EXISTS (SELECT 1 FROM retired_badge_identifiers WHERE (event_id = NEW.event_id AND code = NEW.code) OR token_hash = encode(sha256(NEW.qr_token::bytea), 'hex')) THEN
        RAISE EXCEPTION 'Identifiant de badge retiré non réattribuable.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('DROP INDEX IF EXISTS retired_badge_identifiers_site_code');
        DB::statement('DROP INDEX IF EXISTS retired_badge_identifiers_event_code');
        DB::statement('ALTER TABLE retired_badge_identifiers ALTER COLUMN event_id SET NOT NULL');
        DB::statement('ALTER TABLE retired_badge_identifiers ADD PRIMARY KEY (event_id, code)');
        DB::statement('ALTER TABLE badge_print_runs ALTER COLUMN event_id SET NOT NULL');
        DB::statement('ALTER TABLE badge_issuances ALTER COLUMN event_id SET NOT NULL');
        DB::statement('ALTER TABLE holder_cards DROP CONSTRAINT holder_cards_badge_id_simple_foreign');
        DB::statement('ALTER TABLE holder_cards ALTER COLUMN event_id SET NOT NULL');
        DB::statement('ALTER TABLE badges DROP CONSTRAINT badges_site_shape');
        DB::statement('ALTER TABLE badges ALTER COLUMN badge_batch_id SET NOT NULL');
        DB::statement('ALTER TABLE badges ALTER COLUMN event_id SET NOT NULL');
    }
};
