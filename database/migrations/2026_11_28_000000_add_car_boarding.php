<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S08 — Cars : capacité bloquante, embarquement individuel, absences tracées, mouvements des passagers.
 *
 * - groups.capacity : places du car (null = non renseignée : aucune limite appliquée, l'écran le signale). Quand elle est renseignée, elle est BLOQUANTE
 *   (Groups::board refuse au-delà, sous verrou du groupe).
 * - group_members : qui a embarqué la personne (boarded_by) ; une absence porte son motif et son auteur ; une personne ne peut être à la fois embarquée et absente.
 * - access_events : deux nouveaux types de mouvement individuel, « embarquement » et « débarquement ». L'arrivée d'un car n'écrit plus d'« entrée » :
 *   la présence physique reste établie par l'accueil ou le contrôle, jamais déduite de l'arrivée du car.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', fn (Blueprint $t) => $t->unsignedSmallInteger('capacity')->nullable());
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_capacity_range CHECK (capacity IS NULL OR capacity BETWEEN 1 AND 200)');

        Schema::table('group_members', function (Blueprint $t) {
            $t->uuid('boarded_by')->nullable();
            $t->uuid('absent_by')->nullable();
            $t->string('absence_reason', 200)->nullable();
            $t->foreign('boarded_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('absent_by')->references('id')->on('users')->restrictOnDelete();
        });
        // Les absences antérieures (départ en bloc) n'ont pas de motif saisi : elles gardent une mention explicite plutôt qu'un vide.
        DB::statement("UPDATE group_members SET absence_reason = 'Non embarqué au départ (avant l''embarquement individuel)' WHERE absent_at IS NOT NULL AND absence_reason IS NULL");
        DB::statement('ALTER TABLE group_members ADD CONSTRAINT group_members_absence_traced CHECK ((absent_at IS NULL) = (absence_reason IS NULL))');
        DB::statement('ALTER TABLE group_members ADD CONSTRAINT group_members_not_boarded_and_absent CHECK (NOT (boarded_at IS NOT NULL AND absent_at IS NOT NULL))');

        $types = $this->types();
        $types = array_values(array_unique([...$types, 'embarquement', 'debarquement']));
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_type_valid');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_type_valid CHECK (type IN ('.implode(', ', array_map(fn (string $t): string => "'".$t."'", $types)).'))');
    }

    public function down(): void
    {
        if (DB::table('access_events')->whereIn('type', ['embarquement', 'debarquement'])->exists()) {
            throw new RuntimeException('Migration non réversible : des mouvements de passagers existent.');
        }
        $types = array_values(array_diff($this->types(), ['embarquement', 'debarquement']));
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_type_valid');
        DB::statement('ALTER TABLE access_events ADD CONSTRAINT access_events_type_valid CHECK (type IN ('.implode(', ', array_map(fn (string $t): string => "'".$t."'", $types)).'))');
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT group_members_not_boarded_and_absent');
        DB::statement('ALTER TABLE group_members DROP CONSTRAINT group_members_absence_traced');
        Schema::table('group_members', function (Blueprint $t) {
            $t->dropForeign(['boarded_by']);
            $t->dropForeign(['absent_by']);
            $t->dropColumn(['boarded_by', 'absent_by', 'absence_reason']);
        });
        DB::statement('ALTER TABLE groups DROP CONSTRAINT groups_capacity_range');
        Schema::table('groups', fn (Blueprint $t) => $t->dropColumn('capacity'));
    }

    /** @return list<string> types actuellement autorisés, lus dans la contrainte (aucune liste codée en double) */
    private function types(): array
    {
        $definition = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'access_events_type_valid'")->d;
        preg_match_all("/'([a-z_]+)'/", $definition, $found);

        return array_values(array_unique($found[1]));
    }
};
