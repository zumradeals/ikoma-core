<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V1 / lot P2 — cohérence de l'identité d'un titulaire permanent. Migration ADDITIVE : aucune ligne n'est supprimée, aucune contrainte retirée.
 *
 * `persons` est la référence canonique du nom, du prénom et de l'organisation. Les colonnes homonymes de `permanent_holders` sont conservées
 * (lecture, impression, import) mais deviennent un MIROIR dérivé, jamais une seconde source de vérité :
 *  - un titulaire ne s'enregistre que rattaché à une personne EXISTANTE (le déclencheur historique `permanent_holders_ensure_person` crée toujours
 *    la personne quand `person_id` est nul : parcours « nouvelle personne », transactionnel, inchangé ; quand `person_id` est fourni, aucune personne n'est créée) ;
 *  - un nom ou une organisation ne peut plus être écrit directement sur le titulaire : il se corrige dans la personne, puis se propage aux titulaires ACTIFS ;
 *  - un titulaire clos ne se modifie plus (garde existante) : il garde le nom du moment de sa clôture, il n'est pas réécrit rétroactivement ;
 *  - les participations (`visitors`), les journaux, les cartes et les badges déjà émis ne sont pas touchés.
 * L'unicité d'un profil permanent par personne (index unique) et la clé étrangère restent la garantie en cas de requêtes concurrentes.
 *
 * Rapprochement initial : avant ce lot, un nom de titulaire pouvait être modifié sans toucher la personne. Pour les éventuelles lignes divergentes,
 * la valeur du titulaire (la dernière saisie humaine) est reportée une seule fois dans la personne ; le nombre de lignes concernées est signalé par un NOTICE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
DECLARE n integer;
BEGIN
    UPDATE public.persons p SET first_name = h.first_name, last_name = h.last_name, full_name = h.full_name, organisation = h.organisation, updated_at = now()
    FROM public.permanent_holders h
    WHERE h.person_id = p.id AND (p.first_name IS DISTINCT FROM h.first_name OR p.last_name IS DISTINCT FROM h.last_name OR p.full_name IS DISTINCT FROM h.full_name OR p.organisation IS DISTINCT FROM h.organisation);
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'Identités de titulaires rapprochées (valeur du titulaire reportée dans la personne) : %', n;
END $$;

CREATE OR REPLACE FUNCTION public.ikoma_holder_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p record;
BEGIN
    IF TG_OP = 'UPDATE'
       AND NEW.person_id IS NOT DISTINCT FROM OLD.person_id AND NEW.full_name IS NOT DISTINCT FROM OLD.full_name AND NEW.first_name IS NOT DISTINCT FROM OLD.first_name
       AND NEW.last_name IS NOT DISTINCT FROM OLD.last_name AND NEW.organisation IS NOT DISTINCT FROM OLD.organisation THEN
        RETURN NEW;
    END IF;
    -- Changer la personne d'un profil est interdit par la garde existante (« ne change jamais de personne ») : on lui laisse refuser avec son message.
    IF TG_OP = 'UPDATE' AND NEW.person_id IS DISTINCT FROM OLD.person_id THEN
        RETURN NEW;
    END IF;
    SELECT first_name, last_name, full_name, organisation INTO p FROM public.persons WHERE id = NEW.person_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Un profil permanent se rattache à une personne existante.' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF NEW.full_name IS DISTINCT FROM p.full_name OR NEW.first_name IS DISTINCT FROM p.first_name OR NEW.last_name IS DISTINCT FROM p.last_name OR NEW.organisation IS DISTINCT FROM p.organisation THEN
        RAISE EXCEPTION 'Le nom et l''organisation d''un titulaire se corrigent dans l''identité de la personne, pas dans son profil permanent.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
-- Après « permanent_holders_ensure_person » (ordre alphabétique des déclencheurs) : la personne existe déjà quand la garde s'exécute.
CREATE TRIGGER permanent_holders_identity_guard BEFORE INSERT OR UPDATE ON permanent_holders FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_identity_guard();

CREATE OR REPLACE FUNCTION public.ikoma_person_identity_propagate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.first_name IS DISTINCT FROM OLD.first_name OR NEW.last_name IS DISTINCT FROM OLD.last_name OR NEW.full_name IS DISTINCT FROM OLD.full_name OR NEW.organisation IS DISTINCT FROM OLD.organisation THEN
        UPDATE public.permanent_holders SET first_name = NEW.first_name, last_name = NEW.last_name, full_name = NEW.full_name, organisation = NEW.organisation
        WHERE person_id = NEW.id AND status = 'actif';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER persons_identity_propagate AFTER UPDATE ON persons FOR EACH ROW EXECUTE FUNCTION public.ikoma_person_identity_propagate();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS persons_identity_propagate ON persons;
DROP FUNCTION IF EXISTS public.ikoma_person_identity_propagate();
DROP TRIGGER IF EXISTS permanent_holders_identity_guard ON permanent_holders;
DROP FUNCTION IF EXISTS public.ikoma_holder_identity_guard();
SQL);
    }
};
