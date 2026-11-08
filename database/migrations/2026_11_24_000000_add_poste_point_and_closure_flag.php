<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S02 — Autorisations par poste et clôture réservée : migration strictement additive, sans donnée à reprendre.
 *
 * - devices.point_code : point de contrôle physique tenu par la tablette (poste_1, poste_2, poste_3, poste_4, soute). Nul = poste non identifié :
 *   AUCUNE capacité (refus par défaut) ; un appareil muni d'un jeton d'enrôlement a obligatoirement un point (contrainte). Les capacités de chaque point sont
 *   définies dans App\Services\Poste\PostCapabilities, jamais déduites d'un libellé.
 * - settings.poste_hors_reseau_approuve : approbation par l'institution du délai hors ligne (poste_hors_reseau_max_heures) pour les décisions FAVORABLES
 *   prises sans réseau. Valeur de départ « 0 » : tant qu'aucun délai n'est approuvé, aucune décision favorable hors ligne n'est permise. 0 heure ou absence de
 *   valeur ne signifie jamais « illimité ». Aucune durée n'est fixée ici.
 * - categories.closure_supervisor_only : la sortie et la restitution des personnes de cette catégorie sont réservées au superviseur
 *   (clôture « VIP »). Indicateur explicite : aucun libellé de catégorie n'accorde ni ne retire un droit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->string('point_code', 20)->nullable());
        DB::statement("ALTER TABLE devices ADD CONSTRAINT devices_point_code_valid CHECK (point_code IS NULL OR point_code IN ('poste_1', 'poste_2', 'poste_3', 'poste_4', 'soute'))");
        DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_enrolled_has_point CHECK (token_hash IS NULL OR point_code IS NOT NULL)');
        Schema::table('categories', fn (Blueprint $t) => $t->boolean('closure_supervisor_only')->default(false));
        DB::table('settings')->insert(['key' => 'poste_hors_reseau_approuve', 'value' => '0']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->delete();
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('closure_supervisor_only'));
        DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_enrolled_has_point');
        DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_point_code_valid');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('point_code'));
    }
};
