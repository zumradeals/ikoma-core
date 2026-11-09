<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Badges et cartes — contrat d'émission canonique : registre des OPÉRATIONS d'émission (strictement additif).
 *
 * Une émission est une opération identifiée (`operation_id`, fourni par la requête) portant sur une personne canonique, un contexte (participation ou profil permanent)
 * et un type de support. Une ligne n'est jamais modifiée (déclencheur) ; seules les purges de conservation existantes peuvent la supprimer. Le registre ne porte ni droit d'accès ni état de remise.
 *
 * Garanties transactionnelles (côté base, donc valables en concurrence) :
 *  - `badge_issuances_operation_unique` : une opération rejouée ne peut pas produire une seconde ligne (idempotence) ;
 *  - `badge_issuances_one_origin` : un support n'a qu'une seule émission d'origine (émission ou remplacement) ;
 *  - `badges_one_open_nominative` : au plus UN support nominatif « en cours » (Disponible ou Associé) par participation ;
 *  - (existants) `badge_assignments_one_active` / `_one_active_visitor` : jamais deux affectations actives, pour un support comme pour une personne ;
 *    `holder_cards_one_active` : jamais deux cartes actives pour un titulaire ; `retired_badge_identifiers` : aucun numéro ni jeton réutilisé.
 *
 * Aucune clé étrangère vers badges, visiteurs, titulaires ou événements : les purges et la remise à zéro existantes restent inchangées (même choix que badge_print_runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('badges')->whereNotNull('printed_for_visitor_id')->whereIn('status', ['Disponible', 'Associé'])
            ->select('printed_for_visitor_id')->groupBy('printed_for_visitor_id')->havingRaw('count(*) > 1')->count();
        if ($duplicates > 0) {
            throw new RuntimeException('Migration refusée : des participations portent plusieurs supports nominatifs en cours. Les régulariser (annulation explicite) avant de poser la contrainte.');
        }

        Schema::create('badge_issuances', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operation_id');
            $t->string('purpose', 14);
            $t->string('support_type', 16);
            $t->uuid('person_id');
            $t->uuid('visitor_id')->nullable();
            $t->uuid('holder_id')->nullable();
            $t->uuid('event_id');
            $t->uuid('badge_id');
            $t->uuid('assignment_id')->nullable();
            $t->uuid('replaces_badge_id')->nullable();
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('created_at');
            $t->index(['person_id', 'created_at']);
            $t->index('badge_id');
        });
        DB::statement('CREATE UNIQUE INDEX badge_issuances_operation_unique ON badge_issuances (operation_id)');
        DB::statement("CREATE UNIQUE INDEX badge_issuances_one_origin ON badge_issuances (badge_id) WHERE purpose IN ('emission', 'remplacement')");
        DB::statement("ALTER TABLE badge_issuances ADD CONSTRAINT badge_issuances_purpose_valid CHECK (purpose IN ('emission', 'remplacement', 'reaffectation'))");
        DB::statement("ALTER TABLE badge_issuances ADD CONSTRAINT badge_issuances_support_valid CHECK (support_type IN ('badge_temporaire', 'carte_permanente'))");
        DB::statement("ALTER TABLE badge_issuances ADD CONSTRAINT badge_issuances_context_valid CHECK ((support_type = 'carte_permanente') = (holder_id IS NOT NULL) AND (holder_id IS NOT NULL OR visitor_id IS NOT NULL))");
        DB::statement("ALTER TABLE badge_issuances ADD CONSTRAINT badge_issuances_reassign_temp CHECK (purpose <> 'reaffectation' OR (support_type = 'badge_temporaire' AND assignment_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_issuance_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Une opération d''émission enregistrée ne se modifie pas.' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_issuances_immutable BEFORE UPDATE ON badge_issuances FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_issuance_immutable();
SQL);
        // Reprise de l'existant (lecture seule des données actuelles, aucune modification) : cartes permanentes et supports nominatifs déjà émis entrent dans le registre
        // comme émissions d'origine, afin qu'ils restent imprimables par le contrat unique. Les supports nominatifs dont la participation n'est pas rattachée à une personne canonique n'y entrent pas.
        DB::statement("INSERT INTO badge_issuances (id, operation_id, purpose, support_type, person_id, holder_id, event_id, badge_id, actor_id, created_at)
            SELECT gen_random_uuid(), gen_random_uuid(), CASE WHEN k.rank = 1 THEN 'emission' ELSE 'remplacement' END, 'carte_permanente', h.person_id, k.holder_id, k.event_id, k.badge_id, k.issued_by, k.issued_at
            FROM holder_cards k JOIN permanent_holders h ON h.id = k.holder_id");
        DB::statement("INSERT INTO badge_issuances (id, operation_id, purpose, support_type, person_id, visitor_id, event_id, badge_id, created_at)
            SELECT gen_random_uuid(), gen_random_uuid(), 'emission', 'badge_temporaire', v.person_id, v.id, b.event_id, b.id, b.created_at
            FROM badges b JOIN visitors v ON v.id = b.printed_for_visitor_id
            WHERE v.person_id IS NOT NULL AND v.holder_id IS NULL AND NOT EXISTS (SELECT 1 FROM badge_issuances i WHERE i.badge_id = b.id)");
        // Au plus un support nominatif en cours par participation, même en requêtes simultanées.
        DB::statement("CREATE UNIQUE INDEX badges_one_open_nominative ON badges (printed_for_visitor_id) WHERE printed_for_visitor_id IS NOT NULL AND status IN ('Disponible', 'Associé')");
    }

    public function down(): void
    {
        if (DB::table('badge_issuances')->exists()) {
            throw new RuntimeException('Retour destructif interdit : le registre des émissions contient des opérations.');
        }
        DB::statement('DROP INDEX IF EXISTS badges_one_open_nominative');
        DB::unprepared('DROP TRIGGER IF EXISTS badge_issuances_immutable ON badge_issuances; DROP FUNCTION IF EXISTS public.ikoma_badge_issuance_immutable();');
        Schema::dropIfExists('badge_issuances');
    }
};
