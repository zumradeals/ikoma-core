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
 * - garde SQL : aucune personne ne peut être embarquée si les places du car ne sont pas renseignées (strictement positives) ou si le car est complet ;
 * - notifications : type « car_arrive » (le car de l'invité est arrivé), distinct de « arrivee » (l'invité vient d'entrer) ;
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

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_group_boarding_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cap integer; n integer;
BEGIN
    IF NEW.boarded_at IS NOT NULL AND OLD.boarded_at IS NULL THEN
        SELECT capacity INTO cap FROM public.groups WHERE id = NEW.group_id;
        IF cap IS NULL OR cap < 1 THEN
            RAISE EXCEPTION 'Les places du car doivent être renseignées avant tout embarquement.' USING ERRCODE = 'check_violation';
        END IF;
        SELECT count(*) INTO n FROM public.group_members m JOIN public.visitors v ON v.id = m.visitor_id
            WHERE m.group_id = NEW.group_id AND m.boarded_at IS NOT NULL AND v.withdrawn_at IS NULL AND m.id <> NEW.id;
        IF n >= cap THEN
            RAISE EXCEPTION 'Le car est complet : plus aucune place.' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER group_members_boarding_guard BEFORE UPDATE OF boarded_at ON group_members FOR EACH ROW EXECUTE FUNCTION public.ikoma_group_boarding_guard();
SQL);
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_valid');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_valid CHECK (type IN ('arrivee','car_arrive','non_liste','hors_periode'))");

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
        DB::table('notifications')->where('type', 'car_arrive')->delete();
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_valid');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_valid CHECK (type IN ('arrivee','non_liste','hors_periode'))");
        DB::unprepared('DROP TRIGGER IF EXISTS group_members_boarding_guard ON group_members; DROP FUNCTION IF EXISTS public.ikoma_group_boarding_guard();');
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
