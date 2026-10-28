<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badge_batches', function (Blueprint $t) {
            $t->uuid('category_id')->nullable();
            $t->foreign(['category_id', 'event_id'])->references(['id', 'event_id'])->on('categories')->restrictOnDelete();
        });
        Schema::table('badges', function (Blueprint $t) {
            $t->uuid('category_id')->nullable();
            $t->uuid('reserved_group_id')->nullable();
            $t->foreign(['category_id', 'event_id'])->references(['id', 'event_id'])->on('categories')->restrictOnDelete();
            $t->foreign(['reserved_group_id', 'event_id'])->references(['id', 'event_id'])->on('groups')->restrictOnDelete();
            $t->dropUnique(['code']);
            $t->unique(['event_id', 'code']);
            $t->index('badge_batch_id');
        });
        Schema::table('badge_assignments', function (Blueprint $t) {
            $t->text('cancellation_reason')->nullable();
        });
        Schema::table('access_events', function (Blueprint $t) {
            $t->uuid('corrige_id')->nullable();
            $t->foreign('corrige_id')->references('id')->on('access_events')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE badges ADD CONSTRAINT badges_one_reservation CHECK (reserved_device_id IS NULL OR reserved_group_id IS NULL)');
        DB::statement('CREATE UNIQUE INDEX badge_assignments_one_active_visitor ON badge_assignments (visitor_id) WHERE returned_at IS NULL AND cancelled_at IS NULL');
        DB::table('settings')->insert([['key' => 'badge_marge_pourcent', 'value' => '10'], ['key' => 'badge_reutilisable', 'value' => 'non']]);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_status_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
        (OLD.status = 'Disponible' AND NEW.status = 'Associé')
        OR (OLD.status = 'Associé' AND NEW.status IN ('Restitué', 'Annulé'))
        OR (OLD.status = 'Restitué' AND NEW.status IN ('Disponible', 'Clos'))
    ) THEN
        RAISE EXCEPTION 'Transition de statut de badge interdite : % vers %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER badges_status_guard BEFORE UPDATE ON badges FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_status_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS badges_status_guard ON badges');
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_badge_status_guard()');
        DB::table('settings')->whereIn('key', ['badge_marge_pourcent', 'badge_reutilisable'])->delete();
        DB::statement('DROP INDEX IF EXISTS badge_assignments_one_active_visitor');
        Schema::table('access_events', function (Blueprint $t) {
            $t->dropForeign(['corrige_id']);
            $t->dropColumn('corrige_id');
        });
        Schema::table('badge_assignments', function (Blueprint $t) {
            $t->dropColumn('cancellation_reason');
        });
        Schema::table('badges', function (Blueprint $t) {
            $t->dropIndex(['badge_batch_id']);
            $t->dropUnique(['event_id', 'code']);
            $t->unique('code');
            $t->dropForeign(['reserved_group_id', 'event_id']);
            $t->dropForeign(['category_id', 'event_id']);
            $t->dropColumn(['reserved_group_id', 'category_id']);
        });
        Schema::table('badge_batches', function (Blueprint $t) {
            $t->dropForeign(['category_id', 'event_id']);
            $t->dropColumn('category_id');
        });
    }
};
