<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A05 — Cartes institutionnelles des titulaires permanents : migration strictement additive.
 *
 * - holder_cards : une carte physique = un badge technique du contexte permanent (jeton QR IKOMA1 inchangé) rattaché à un titulaire.
 *   Une seule carte active par titulaire. La carte n'ouvre aucun accès : le badge reste non associé tant qu'aucune règle d'événement ne l'associe.
 * - holder_card_prints : historique des tirages (émission puis réimpressions). Append-only, imposé par déclencheur et par droits.
 * - permanent_holders.photo_file / photo_print_allowed : portrait facultatif, chiffré, imprimé seulement si autorisé.
 * Aucun identifiant de titulaire, code de badge ou jeton n'est modifié par cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permanent_holders', function (Blueprint $t) {
            $t->string('photo_file', 255)->nullable();
            $t->boolean('photo_print_allowed')->default(false);
        });

        Schema::create('holder_cards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('holder_id');
            $t->uuid('badge_id');
            $t->uuid('event_id');
            $t->string('status', 10)->default('active');
            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at');
            $t->timestampsTz();
            $t->foreign('holder_id')->references('id')->on('permanent_holders')->restrictOnDelete();
            $t->foreign(['badge_id', 'event_id'])->references(['id', 'event_id'])->on('badges')->restrictOnDelete();
            $t->foreign('issued_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique('badge_id');
        });
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_status_valid CHECK (status IN ('active', 'retiree'))");
        // Garantie de base : jamais deux cartes actives pour un même titulaire, même en cas de requêtes simultanées.
        DB::statement("CREATE UNIQUE INDEX holder_cards_one_active ON holder_cards (holder_id) WHERE status = 'active'");

        Schema::create('holder_card_prints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('card_id');
            $t->string('kind', 12);
            $t->string('reason', 200)->nullable();
            $t->uuid('printed_by')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('card_id')->references('id')->on('holder_cards')->restrictOnDelete();
            $t->foreign('printed_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['card_id', 'created_at']);
        });
        DB::statement("ALTER TABLE holder_card_prints ADD CONSTRAINT holder_card_prints_kind_valid CHECK (kind IN ('emission', 'reimpression'))");
        DB::statement("ALTER TABLE holder_card_prints ADD CONSTRAINT holder_card_prints_reason_coherent CHECK ((kind = 'reimpression') = (reason IS NOT NULL))");
        // Un seul tirage d'émission par carte ; tous les suivants sont des réimpressions motivées.
        DB::statement("CREATE UNIQUE INDEX holder_card_prints_one_emission ON holder_card_prints (card_id) WHERE kind = 'emission'");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_holder_card_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        -- Une table vide peut être vidée (remise à zéro de démonstration, qui est déjà refusée dès que le contexte permanent existe) ; jamais une table qui contient des cartes.
        IF EXISTS (SELECT 1 FROM public.holder_cards) OR EXISTS (SELECT 1 FROM public.holder_card_prints) THEN
            RAISE EXCEPTION 'Les cartes de titulaires et leur historique ne se remettent pas à zéro.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NULL;
    END IF;
    IF TG_TABLE_NAME = 'holder_card_prints' THEN
        RAISE EXCEPTION 'L''historique des tirages de cartes est en ajout seul.' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Une carte ne se supprime pas.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.holder_id IS DISTINCT FROM OLD.holder_id OR NEW.badge_id IS DISTINCT FROM OLD.badge_id OR NEW.event_id IS DISTINCT FROM OLD.event_id THEN
        RAISE EXCEPTION 'Une carte reste attachée à son titulaire et à son badge.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER holder_card_prints_append_only BEFORE UPDATE OR DELETE ON holder_card_prints FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_card_guard();
CREATE TRIGGER holder_card_prints_no_truncate BEFORE TRUNCATE ON holder_card_prints FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_holder_card_guard();
CREATE TRIGGER holder_cards_immutable BEFORE UPDATE OR DELETE ON holder_cards FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_card_guard();
CREATE TRIGGER holder_cards_no_truncate BEFORE TRUNCATE ON holder_cards FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_holder_card_guard();
SQL);
        // La remise à zéro de démonstration vide les badges SANS CASCADE : les nouvelles tables dépendantes doivent y figurer (revue du contrat voulue par cette fonction).
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $updated = str_replace('public.badges, public.visitors', 'public.holder_card_prints, public.holder_cards, public.badges, public.visitors', $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro inattendue : revue du contrat requise.');
        }
        DB::unprepared($updated);
    }

    public function down(): void
    {
        if (Schema::hasTable('holder_cards') && DB::table('holder_cards')->exists()) {
            throw new RuntimeException('Migration non réversible : des cartes de titulaires ont été émises.');
        }
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        DB::unprepared(str_replace('public.holder_card_prints, public.holder_cards, public.badges', 'public.badges', $definition));
        DB::unprepared('DROP TRIGGER IF EXISTS holder_cards_no_truncate ON holder_cards; DROP TRIGGER IF EXISTS holder_cards_immutable ON holder_cards; DROP TRIGGER IF EXISTS holder_card_prints_no_truncate ON holder_card_prints; DROP TRIGGER IF EXISTS holder_card_prints_append_only ON holder_card_prints;');
        Schema::dropIfExists('holder_card_prints');
        Schema::dropIfExists('holder_cards');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_holder_card_guard();');
        Schema::table('permanent_holders', fn (Blueprint $t) => $t->dropColumn(['photo_file', 'photo_print_allowed']));
    }
};
