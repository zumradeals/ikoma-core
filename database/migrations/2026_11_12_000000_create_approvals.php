<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double validation des actions critiques (audit global D2, profil de sécurité renforcé) : une personne demande, une autre la valide.
 * La base interdit qu'une demande soit validée par son auteur. La charge utile ne contient aucun secret en clair (un mot de passe n'y figure qu'en empreinte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('action', 40);
            $t->jsonb('payload');
            $t->string('summary', 200); // phrase lisible par le validateur, sans mot de passe
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('expires_at');
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision', 10)->nullable();
            $t->timestampTz('executed_at')->nullable();
            $t->timestampsTz();
            $t->index(['decision', 'expires_at']);
        });
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_decision_valid CHECK (decision IS NULL OR decision IN ('approuve','refuse'))");
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_decision_pair CHECK ((decision IS NULL) = (decided_by IS NULL) AND (decision IS NULL) = (decided_at IS NULL))');
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_two_people CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_executed_only_if_approved CHECK (executed_at IS NULL OR COALESCE(decision, '') = 'approuve')");
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
