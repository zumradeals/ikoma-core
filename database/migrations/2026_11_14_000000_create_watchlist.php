<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit global A2 : liste d'interdits persistante entre les événements, gérée par l'officier de sécurité. Une entrée porte un nom et un motif ;
 * le motif n'est jamais montré à l'agent d'accueil. La comparaison se fait sur une clé de nom sans accent, sans majuscule et sans ordre des mots.
 */
return new class extends Migration
{
    private const TYPES_BEFORE = "'refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet','groupe_ecart','controle_refuse','zone_interdite','badge_double','controle_rafale','contrainte'";

    public function up(): void
    {
        DB::statement("CREATE OR REPLACE FUNCTION public.ikoma_name_key(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS \$\$ SELECT COALESCE(array_to_string(ARRAY(SELECT w FROM unnest(regexp_split_to_array(btrim(regexp_replace(public.ikoma_normalize(\$1), '[^[:alnum:]]+', ' ', 'g')), ' +')) AS w WHERE w <> '' ORDER BY w), ' '), '') \$\$");
        Schema::create('watchlist_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('full_name', 160);
            $t->string('name_key', 200);
            $t->text('reason');
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('lifted_at')->nullable();
            $t->foreignUuid('lifted_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE watchlist_entries ADD CONSTRAINT watchlist_name_not_empty CHECK (name_key <> '')");
        DB::statement('ALTER TABLE watchlist_entries ADD CONSTRAINT watchlist_lift_pair CHECK ((lifted_at IS NULL) = (lifted_by IS NULL))');
        DB::statement('CREATE UNIQUE INDEX watchlist_active_key ON watchlist_entries (name_key) WHERE lifted_at IS NULL');
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::TYPES_BEFORE.",'personne_signalee'))");
    }

    public function down(): void
    {
        DB::table('alerts')->where('type', 'personne_signalee')->delete();
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::TYPES_BEFORE.'))');
        Schema::dropIfExists('watchlist_entries');
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_name_key(text)');
    }
};
