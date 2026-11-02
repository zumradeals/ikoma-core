<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes permanentes : migration strictement additive.
 *
 * - holder_cards.activated_at / activated_by / activation_source : une carte NOUVELLE n'ouvre rien tant qu'elle n'a pas été remise (« Remettre et activer »).
 *   Les cartes déjà émises restent utilisables : elles sont marquées « ancienne carte » (leur date d'émission sert de date d'activation, sans prétendre à une remise constatée).
 * - card_models : un modèle par catégorie institutionnelle (couleur, mention, validité affichée, position du QR), avec version et validation.
 *   Aucune ligne n'est créée d'avance : sans ligne, le modèle prend les valeurs par défaut de la catégorie.
 * - holder_card_prints.model_version : version du modèle utilisée par un tirage (jamais modifiée ensuite).
 * - badge_print_runs : le registre des tirages de l'Atelier accepte aussi les cartes permanentes (type « permanent », format « cr80 »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holder_cards', function (Blueprint $t) {
            $t->timestampTz('activated_at')->nullable();
            $t->uuid('activated_by')->nullable();
            $t->string('activation_source', 12)->nullable();
            $t->foreign('activated_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_activation_valid CHECK (activation_source IS NULL OR activation_source IN ('ancienne', 'remise'))");
        DB::statement('ALTER TABLE holder_cards ADD CONSTRAINT holder_cards_activation_coherent CHECK ((activated_at IS NULL) = (activation_source IS NULL))');
        // Cartes existantes : utilisables comme avant, marquées « ancienne carte ».
        DB::statement("UPDATE holder_cards SET activated_at = issued_at, activation_source = 'ancienne' WHERE status = 'active'");

        Schema::create('card_models', function (Blueprint $t) {
            $t->string('category_code', 12)->primary();
            $t->string('color', 7)->nullable();
            $t->string('mention', 60)->nullable();
            $t->boolean('show_validity')->default(true);
            $t->string('qr_side', 6)->default('verso');
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 10)->default('a_valider');
            $t->timestampTz('validated_at')->nullable();
            $t->uuid('validated_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign('category_code')->references('code')->on('institutional_categories')->restrictOnDelete();
            $t->foreign('validated_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE card_models ADD CONSTRAINT card_models_status_valid CHECK (status IN ('a_valider', 'valide'))");
        DB::statement("ALTER TABLE card_models ADD CONSTRAINT card_models_qr_side_valid CHECK (qr_side IN ('recto', 'verso'))");
        DB::statement("ALTER TABLE card_models ADD CONSTRAINT card_models_color_valid CHECK (color IS NULL OR color ~ '^#[0-9A-Fa-f]{6}$')");
        DB::statement("ALTER TABLE card_models ADD CONSTRAINT card_models_validation_coherent CHECK ((status = 'valide') = (validated_at IS NOT NULL AND validated_by IS NOT NULL))");

        Schema::table('holder_card_prints', function (Blueprint $t) {
            $t->unsignedInteger('model_version')->nullable();
        });

        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_kind_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur', 'permanent'))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_format_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_format_valid CHECK (format IN ('pvc', 'a4', 'etiquettes', 'cr80'))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_nature_batch');
        // Visiteurs : un essai n'utilise jamais un vrai lot, un tirage officiel en désigne un. Cartes permanentes : aucun lot de visiteurs.
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((kind = 'permanent' AND batch_id IS NULL) OR (kind = 'visiteur' AND ((nature = 'essai') = (batch_id IS NULL))))");
    }

    public function down(): void
    {
        if (DB::table('badge_print_runs')->where('kind', 'permanent')->exists() || DB::table('card_models')->exists() || DB::table('holder_cards')->where('activation_source', 'remise')->exists()) {
            throw new RuntimeException('Migration non réversible : des modèles, des remises de cartes ou des tirages de cartes existent.');
        }
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_nature_batch');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_nature_batch CHECK ((nature = 'essai') = (batch_id IS NULL))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_format_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_format_valid CHECK (format IN ('pvc', 'a4', 'etiquettes'))");
        DB::statement('ALTER TABLE badge_print_runs DROP CONSTRAINT badge_print_runs_kind_valid');
        DB::statement("ALTER TABLE badge_print_runs ADD CONSTRAINT badge_print_runs_kind_valid CHECK (kind IN ('visiteur'))");
        Schema::table('holder_card_prints', fn (Blueprint $t) => $t->dropColumn('model_version'));
        Schema::dropIfExists('card_models');
        DB::statement('ALTER TABLE holder_cards DROP CONSTRAINT IF EXISTS holder_cards_activation_coherent, DROP CONSTRAINT IF EXISTS holder_cards_activation_valid');
        Schema::table('holder_cards', function (Blueprint $t) {
            $t->dropForeign(['activated_by']);
            $t->dropColumn(['activated_at', 'activated_by', 'activation_source']);
        });
    }
};
