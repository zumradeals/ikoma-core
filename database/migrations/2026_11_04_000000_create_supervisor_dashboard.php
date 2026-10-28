<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('type', 40);
            $t->string('severity', 10);
            $t->string('subject_type', 20)->nullable();
            $t->uuid('subject_id')->nullable();
            // Une même cause ne crée qu'une alerte : le rejeu d'une opération ou d'un événement ne double rien.
            $t->string('dedupe_key', 120)->nullable()->unique();
            $t->string('message', 200); // courte, sans nom : le nom n'est affiché qu'à la consultation de la fiche
            $t->foreignUuid('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('acknowledged_at')->nullable();
            $t->timestampTz('resolved_at')->nullable(); // réglée d'elle-même (le non listé a reçu sa réponse, le badge a été remplacé…)
            $t->timestampsTz();
            $t->index(['event_id', 'acknowledged_at', 'resolved_at']);
            $t->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_severity_valid CHECK (severity IN ('critique','haute','normale'))");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet'))");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_subject_valid CHECK (subject_type IS NULL OR subject_type IN ('visiteur','badge','conflit','groupe'))");
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_ack_pair CHECK ((acknowledged_by IS NULL) = (acknowledged_at IS NULL))');
        Schema::table('refusals', function (Blueprint $t) {
            $t->string('decision', 20)->nullable();
            $t->timestampTz('decided_at')->nullable();
        });
        DB::statement("ALTER TABLE refusals ADD CONSTRAINT refusals_decision_valid CHECK (decision IS NULL OR decision IN ('maintenu','autorise','a_revoir'))");
        Schema::table('visitors', function (Blueprint $t) {
            $t->text('access_note')->nullable(); // mention de l'autorisation donnée par le superviseur après un refus
        });
    }

    public function down(): void
    {
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn('access_note'));
        DB::statement('ALTER TABLE refusals DROP CONSTRAINT refusals_decision_valid');
        Schema::table('refusals', fn (Blueprint $t) => $t->dropColumn(['decision', 'decided_at']));
        Schema::dropIfExists('alerts');
    }
};
