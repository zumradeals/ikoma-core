<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A06 — Cycle de vie des cartes permanentes, habilitations et politiques de zone : migration strictement additive.
 *
 * - holder_cards : statuts terminaux (perdue, volée, révoquée), motif, auteur, remplacement et rang d'émission. Une carte non active ne redevient jamais active (déclencheur).
 * - holder_entitlements : habilitation explicite d'un titulaire à UNE zone d'UN événement, pour une période. Aucune habilitation implicite : les catégories AILCT n'en confèrent jamais.
 * - zone_policies : classification et autorisations de la zone vis-à-vis des titulaires permanents. Sans ligne, la zone refuse les titulaires permanents (refus par défaut).
 * Les réglages de confiance hors ligne sont des réglages (table settings), jamais des constantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holder_cards', function (Blueprint $t) {
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->string('revocation_reason', 20)->nullable();
            $t->string('revocation_note', 200)->nullable();
            $t->uuid('replaced_by_card_id')->nullable();
            $t->smallInteger('rank')->default(1);
            $t->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('replaced_by_card_id')->references('id')->on('holder_cards')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE holder_cards DROP CONSTRAINT holder_cards_status_valid');
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_status_valid CHECK (status IN ('active', 'retiree', 'perdue', 'volee', 'revoquee'))");
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_revocation_coherent CHECK ((status IN ('active', 'retiree')) OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL))");
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_reason_valid CHECK (revocation_reason IS NULL OR revocation_reason IN ('perdue', 'volee', 'compromise', 'autre'))");
        DB::statement('CREATE UNIQUE INDEX holder_cards_holder_rank ON holder_cards (holder_id, rank)');

        Schema::create('zone_policies', function (Blueprint $t) {
            $t->uuid('zone_id')->primary();
            $t->uuid('event_id');
            $t->string('classification', 40);
            $t->boolean('permanent_allowed')->default(false);
            $t->boolean('offline_entry_allowed')->default(false);
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign(['zone_id', 'event_id'])->references(['id', 'event_id'])->on('zones')->cascadeOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('holder_entitlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('holder_id');
            $t->uuid('event_id');
            $t->uuid('zone_id');
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_until');
            $t->uuid('granted_by')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->string('revocation_reason', 200)->nullable();
            $t->timestampsTz();
            $t->foreign('holder_id')->references('id')->on('permanent_holders')->restrictOnDelete();
            $t->foreign(['zone_id', 'event_id'])->references(['id', 'event_id'])->on('zones')->restrictOnDelete();
            $t->foreign('granted_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('revoked_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['event_id', 'zone_id']);
            $t->index(['holder_id', 'event_id']);
        });
        DB::statement('ALTER TABLE holder_entitlements ADD CONSTRAINT holder_entitlements_period CHECK (valid_until > valid_from)');
        DB::statement('ALTER TABLE holder_entitlements ADD CONSTRAINT holder_entitlements_revoked_coherent CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_holder_card_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        -- Une table vide peut être vidée (remise à zéro de démonstration, déjà refusée dès que le contexte permanent existe) ; jamais une table qui contient des cartes ou des habilitations.
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
    IF NEW.holder_id IS DISTINCT FROM OLD.holder_id OR NEW.badge_id IS DISTINCT FROM OLD.badge_id OR NEW.event_id IS DISTINCT FROM OLD.event_id OR NEW.rank IS DISTINCT FROM OLD.rank THEN
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
CREATE TRIGGER holder_entitlements_guard BEFORE UPDATE OR DELETE ON holder_entitlements FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_card_guard();
CREATE TRIGGER holder_entitlements_no_truncate BEFORE TRUNCATE ON holder_entitlements FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_holder_card_guard();
SQL);
        // La remise à zéro de démonstration vide les zones SANS CASCADE : les nouvelles tables dépendantes doivent y figurer.
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $updated = str_replace('public.holder_card_prints, public.holder_cards,', 'public.holder_entitlements, public.zone_policies, public.holder_card_prints, public.holder_cards,', $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro inattendue : revue du contrat requise.');
        }
        DB::unprepared($updated);
    }

    public function down(): void
    {
        if ((Schema::hasTable('holder_entitlements') && DB::table('holder_entitlements')->exists()) || DB::table('holder_cards')->where('status', '<>', 'active')->exists()) {
            throw new RuntimeException('Migration non réversible : des habilitations ou des cartes révoquées existent.');
        }
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.holder_entitlements, public.zone_policies, ', '', $definition));
        DB::unprepared('DROP TRIGGER IF EXISTS holder_entitlements_no_truncate ON holder_entitlements; DROP TRIGGER IF EXISTS holder_entitlements_guard ON holder_entitlements;');
        Schema::dropIfExists('holder_entitlements');
        Schema::dropIfExists('zone_policies');
        DB::statement('DROP INDEX IF EXISTS holder_cards_holder_rank');
        DB::statement('ALTER TABLE holder_cards DROP CONSTRAINT IF EXISTS holder_cards_reason_valid, DROP CONSTRAINT IF EXISTS holder_cards_revocation_coherent, DROP CONSTRAINT holder_cards_status_valid');
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_status_valid CHECK (status IN ('active', 'retiree'))");
        Schema::table('holder_cards', function (Blueprint $t) {
            $t->dropForeign(['revoked_by']);
            $t->dropForeign(['replaced_by_card_id']);
            $t->dropColumn(['revoked_at', 'revoked_by', 'revocation_reason', 'revocation_note', 'replaced_by_card_id', 'rank']);
        });
    }
};
