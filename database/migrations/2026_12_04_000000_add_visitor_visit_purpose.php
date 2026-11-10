<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motif de la visite d'une personne attendue (« Visite de site », « Réunion »…), affiché à l'accueil dans l'aperçu de la personne.
 * Facultatif, texte libre court : aucune règle d'accès n'en dépend. La fonction existe déjà (visitors.position).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', fn (Blueprint $t) => $t->string('visit_purpose', 200)->nullable());
    }

    public function down(): void
    {
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn('visit_purpose'));
    }
};
