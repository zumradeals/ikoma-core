<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 — Registre des personnes : migration strictement additive.
 *
 * - first_name / last_name : prénom et nom séparés, facultatifs. Les fiches historiques gardent leur nom complet tel quel ; aucune séparation automatique n'est tentée.
 * - quality : qualité de la personne (par exemple « Stagiaire », « Représentant »), distincte de la fonction (position, existante).
 * full_name reste la valeur de référence (unicité, recherche, carte) : quand le prénom et le nom sont fournis, il en est dérivé côté serveur.
 * Aucune carte, aucun badge, aucun QR, aucune habilitation n'est touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permanent_holders', function (Blueprint $t) {
            $t->string('first_name', 100)->nullable();
            $t->string('last_name', 100)->nullable();
            $t->string('quality', 160)->nullable();
        });
        // Le prénom et le nom vont ensemble : jamais l'un sans l'autre.
        DB::statement('ALTER TABLE permanent_holders ADD CONSTRAINT permanent_holders_split_name_together CHECK ((first_name IS NULL) = (last_name IS NULL))');
    }

    public function down(): void
    {
        if (DB::table('permanent_holders')->where(fn ($q) => $q->whereNotNull('first_name')->orWhereNotNull('last_name')->orWhereNotNull('quality'))->exists()) {
            throw new RuntimeException('Migration non réversible : des fiches portent un prénom, un nom ou une qualité.');
        }
        DB::statement('ALTER TABLE permanent_holders DROP CONSTRAINT IF EXISTS permanent_holders_split_name_together');
        Schema::table('permanent_holders', fn (Blueprint $t) => $t->dropColumn(['first_name', 'last_name', 'quality']));
    }
};
