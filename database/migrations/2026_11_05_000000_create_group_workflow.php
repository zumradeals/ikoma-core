<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source de vérité de l'appartenance : visitors.group_id. group_members porte l'avancement de chaque stagiaire dans son groupe ;
        // la clé étrangère (visitor_id, group_id) vers visitors et l'unicité de visitor_id garantissent qu'une ligne ne peut exister
        // que pour le groupe auquel le stagiaire appartient, et qu'un stagiaire n'est que dans un seul groupe.
        Schema::table('group_members', function (Blueprint $t) {
            $t->timestampTz('called_at')->nullable();          // appel nominal
            $t->timestampTz('checked_at')->nullable();         // contrôle de correspondance fait
            $t->string('check_mode', 10)->nullable();          // identite | encadreur
            $t->foreignUuid('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at')->nullable();       // confirmation de présence par l'encadreur
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('absent_at')->nullable();          // absent à la fermeture du car
            $t->unique('visitor_id');
        });
        DB::statement("ALTER TABLE group_members ADD CONSTRAINT group_members_mode_valid CHECK (check_mode IS NULL OR check_mode IN ('identite','encadreur'))");
        DB::statement('ALTER TABLE group_members ADD CONSTRAINT group_members_confirmation_pair CHECK ((confirmed_at IS NULL) = (confirmed_by IS NULL))');
        Schema::table('groups', function (Blueprint $t) {
            $t->timestampTz('opened_at')->nullable();
            $t->timestampTz('departed_at')->nullable();        // car fermé : embarquement enregistré
            $t->foreignUuid('departed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('arrival_closed_at')->nullable();  // contrôle collectif d'arrivée terminé
        });
        DB::table('settings')->insert(['key' => 'controle_stagiaires', 'value' => 'encadreur']);
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet','groupe_ecart'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement("DELETE FROM alerts WHERE type = 'groupe_ecart'");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet'))");
        DB::table('settings')->where('key', 'controle_stagiaires')->delete();
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn(['opened_at', 'departed_at', 'departed_by', 'arrival_closed_at']));
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT group_members_confirmation_pair');
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT group_members_mode_valid');
        Schema::table('group_members', function (Blueprint $t) {
            $t->dropUnique(['visitor_id']);
            $t->dropColumn(['called_at', 'checked_at', 'check_mode', 'checked_by', 'confirmed_at', 'confirmed_by', 'absent_at']);
        });
    }
};
