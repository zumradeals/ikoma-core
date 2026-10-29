<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $t) {
            $t->string('position', 160)->nullable();
            $t->string('profile_photo_file')->nullable();
            $t->boolean('profile_photo_print_allowed')->default(false);
        });
        Schema::table('badges', function (Blueprint $t) {
            $t->uuid('printed_for_visitor_id')->nullable();
            $t->foreign(['printed_for_visitor_id', 'event_id'])->references(['id', 'event_id'])->on('visitors')->restrictOnDelete();
            $t->index('printed_for_visitor_id');
        });
        // Une carte portant une identité ne retourne jamais silencieusement dans le stock anonyme.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_printed_person_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.printed_for_visitor_id IS NOT NULL AND NEW.printed_for_visitor_id IS DISTINCT FROM OLD.printed_for_visitor_id THEN
        RAISE EXCEPTION 'La destination nominative est immuable : annuler la carte et utiliser un autre badge.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER badges_printed_person_guard BEFORE UPDATE ON badges FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_printed_person_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('badges')->whereNotNull('printed_for_visitor_id')->exists() || DB::table('visitors')->whereNotNull('profile_photo_file')->exists()) {
            throw new RuntimeException('Retour destructif interdit après préparation nominative.');
        }
        DB::unprepared('DROP TRIGGER badges_printed_person_guard ON badges; DROP FUNCTION public.ikoma_badge_printed_person_guard();');
        Schema::table('badges', function (Blueprint $t) {
            $t->dropForeign(['printed_for_visitor_id', 'event_id']);
            $t->dropColumn('printed_for_visitor_id');
        });
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn(['position', 'profile_photo_file', 'profile_photo_print_allowed']));
    }
};
