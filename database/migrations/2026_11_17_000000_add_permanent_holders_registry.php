<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04 — Registre des titulaires permanents (approche C1) : migration strictement additive.
 *
 * - institutional_categories : référentiel des catégories institutionnelles (aucun droit d'accès).
 * - permanent_holders : registre d'identité hors de tout événement ; identifiant imprimable immuable et jamais réattribué.
 * - events.is_permanent : contexte permanent protégé (jamais clos, archivé, anonymisé ni supprimé) ; aucun comportement de carte ici.
 * - categories.institutional_code et visitors.holder_id : liens facultatifs et nullables ; les données existantes ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutional_categories', function (Blueprint $t) {
            $t->string('code', 12)->primary();
            $t->string('label', 80);
            $t->char('color', 7);
            $t->string('symbol', 8);
            $t->smallInteger('sort_order');
            $t->timestampsTz();
        });

        Schema::create('permanent_holders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('identifier', 40)->unique();
            $t->bigInteger('identifier_seq')->unique();
            $t->string('full_name', 160);
            $t->string('organisation', 160)->nullable();
            $t->string('position', 160)->nullable();
            $t->string('institutional_category', 12)->nullable();
            $t->date('engagement_starts_on')->nullable();
            $t->date('engagement_ends_on')->nullable();
            $t->string('status', 10)->default('actif');
            $t->timestampTz('closed_at')->nullable();
            $t->string('closed_reason', 200)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign('institutional_category')->references('code')->on('institutional_categories')->restrictOnDelete()->cascadeOnUpdate();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });
        // La séquence appartient à la table : migrate:fresh la supprime avec elle, et elle n'est jamais remise à zéro sinon.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS permanent_holder_identifier_seq AS bigint START 1 NO CYCLE OWNED BY permanent_holders.identifier_seq');
        DB::statement("ALTER TABLE permanent_holders ADD CONSTRAINT permanent_holders_status_valid CHECK (status IN ('actif', 'clos'))");
        DB::statement("ALTER TABLE permanent_holders ADD CONSTRAINT permanent_holders_closed_coherent CHECK ((status = 'clos') = (closed_at IS NOT NULL))");
        DB::statement('ALTER TABLE permanent_holders ADD CONSTRAINT permanent_holders_engagement_dates CHECK (engagement_ends_on IS NULL OR engagement_starts_on IS NULL OR engagement_ends_on >= engagement_starts_on)');
        // Prévention des doublons : une seule fiche active par couple (nom, organisation) normalisés (accents et casse ignorés).
        DB::statement("CREATE UNIQUE INDEX permanent_holders_active_identity ON permanent_holders (public.ikoma_normalize(full_name), public.ikoma_normalize(coalesce(organisation, ''))) WHERE status = 'actif'");
        DB::statement('CREATE INDEX permanent_holders_name_trgm ON permanent_holders USING gin (public.ikoma_normalize(full_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX permanent_holders_status_idx ON permanent_holders (status, institutional_category)');

        Schema::table('events', fn (Blueprint $t) => $t->boolean('is_permanent')->default(false));
        DB::statement('CREATE UNIQUE INDEX events_single_permanent ON events ((is_permanent)) WHERE is_permanent');
        Schema::table('categories', fn (Blueprint $t) => $t->string('institutional_code', 12)->nullable());
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_institutional_code_foreign FOREIGN KEY (institutional_code) REFERENCES institutional_categories(code) ON UPDATE CASCADE ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX categories_event_institutional_unique ON categories (event_id, institutional_code) WHERE institutional_code IS NOT NULL');
        Schema::table('visitors', fn (Blueprint $t) => $t->uuid('holder_id')->nullable());
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_holder_id_foreign FOREIGN KEY (holder_id) REFERENCES permanent_holders(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX visitors_holder_event_unique ON visitors (holder_id, event_id) WHERE holder_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_holder_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
        RAISE EXCEPTION 'Un titulaire permanent ne se supprime pas : il se clôt, et son identifiant n''est jamais réattribué.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.identifier IS DISTINCT FROM OLD.identifier OR NEW.identifier_seq IS DISTINCT FROM OLD.identifier_seq OR NEW.id IS DISTINCT FROM OLD.id THEN
        RAISE EXCEPTION 'L''identifiant d''un titulaire est immuable.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status = 'clos' THEN
        RAISE EXCEPTION 'Un titulaire clos ne se modifie plus.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER permanent_holders_no_delete BEFORE DELETE ON permanent_holders FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_guard();
CREATE TRIGGER permanent_holders_no_truncate BEFORE TRUNCATE ON permanent_holders FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_holder_guard();
CREATE TRIGGER permanent_holders_immutable BEFORE UPDATE ON permanent_holders FOR EACH ROW EXECUTE FUNCTION public.ikoma_holder_guard();

CREATE OR REPLACE FUNCTION public.ikoma_permanent_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'TRUNCATE' THEN
        IF EXISTS (SELECT 1 FROM public.events WHERE is_permanent) THEN
            RAISE EXCEPTION 'Le contexte permanent est protégé : aucune remise à zéro des événements.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NULL;
    END IF;
    IF TG_OP = 'DELETE' THEN
        IF OLD.is_permanent THEN
            RAISE EXCEPTION 'Le contexte permanent ne se supprime pas.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.is_permanent THEN
        IF NEW.is_permanent IS NOT TRUE THEN
            RAISE EXCEPTION 'Le contexte permanent le reste.' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status IN ('clos', 'archive') OR NEW.anonymized_at IS NOT NULL THEN
            RAISE EXCEPTION 'Le contexte permanent ne se clôt, ne s''archive ni ne s''anonymise.' USING ERRCODE = 'check_violation';
        END IF;
    ELSIF NEW.is_permanent AND (NEW.status IN ('clos', 'archive') OR NEW.anonymized_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Un événement clos ou anonymisé ne devient pas le contexte permanent.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER permanent_event_update BEFORE UPDATE ON events FOR EACH ROW EXECUTE FUNCTION public.ikoma_permanent_event_guard();
CREATE TRIGGER permanent_event_delete BEFORE DELETE ON events FOR EACH ROW EXECUTE FUNCTION public.ikoma_permanent_event_guard();
CREATE TRIGGER permanent_event_truncate BEFORE TRUNCATE ON events FOR EACH STATEMENT EXECUTE FUNCTION public.ikoma_permanent_event_guard();
SQL);
    }

    public function down(): void
    {
        // Le registre contient des identités et des identifiants jamais réattribuables : le retour arrière n'est permis que s'il est vide.
        if (Schema::hasTable('permanent_holders') && DB::table('permanent_holders')->exists()) {
            throw new RuntimeException('Migration non réversible : des titulaires permanents existent.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS permanent_event_truncate ON events; DROP TRIGGER IF EXISTS permanent_event_delete ON events; DROP TRIGGER IF EXISTS permanent_event_update ON events;');
        DB::statement('DROP INDEX IF EXISTS visitors_holder_event_unique');
        Schema::table('visitors', function (Blueprint $t) {
            $t->dropForeign('visitors_holder_id_foreign');
            $t->dropColumn('holder_id');
        });
        DB::statement('DROP INDEX IF EXISTS categories_event_institutional_unique');
        Schema::table('categories', function (Blueprint $t) {
            $t->dropForeign('categories_institutional_code_foreign');
            $t->dropColumn('institutional_code');
        });
        DB::statement('DROP INDEX IF EXISTS events_single_permanent');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('is_permanent'));
        Schema::dropIfExists('permanent_holders');
        DB::statement('DROP SEQUENCE IF EXISTS permanent_holder_identifier_seq');
        Schema::dropIfExists('institutional_categories');
        DB::unprepared('DROP FUNCTION IF EXISTS public.ikoma_permanent_event_guard(); DROP FUNCTION IF EXISTS public.ikoma_holder_guard();');
    }
};
