<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approbation des invités par l'officier de sécurité (audit global A3, décision du dirigeant du 28 octobre 2026). Dans le profil renforcé, une liste
 * importée et un invité ajouté par un hôte attendent l'approbation d'une deuxième personne avant tout accueil. Les invités existants restent approuvés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $t) {
            $t->string('approval_status', 12)->default('approuve');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->index(['event_id', 'approval_status']);
        });
        DB::statement("ALTER TABLE visitors ADD CONSTRAINT visitors_approval_status_valid CHECK (approval_status IN ('approuve','a_approuver','refuse'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT visitors_approval_status_valid');
        Schema::table('visitors', function (Blueprint $t) {
            $t->dropIndex(['event_id', 'approval_status']);
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['approval_status', 'approved_at']);
        });
    }
};
