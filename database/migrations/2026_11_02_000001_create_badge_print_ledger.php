<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_print_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('batch_id');
            $table->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $table->foreign('batch_id')->references('id')->on('badge_batches')->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('model', 80);
            $table->string('format', 20);
            $table->string('face', 20);
            $table->unsignedInteger('badge_count');
            $table->string('first_code', 80);
            $table->string('last_code', 80);
            $table->timestampTz('generated_at');
            $table->timestampsTz();
        });

        Schema::create('badge_print_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('print_run_id');
            $table->foreign('print_run_id')->references('id')->on('badge_print_runs')->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
            $table->index(['print_run_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_badge_print_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Historique des impressions immuable' USING ERRCODE = 'check_violation';
END $$;
CREATE TRIGGER badge_print_runs_immutable BEFORE UPDATE OR DELETE ON badge_print_runs
FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_append_only();
CREATE TRIGGER badge_print_events_immutable BEFORE UPDATE OR DELETE ON badge_print_events
FOR EACH ROW EXECUTE FUNCTION public.ikoma_badge_print_append_only();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS badge_print_events_immutable ON badge_print_events');
        DB::statement('DROP TRIGGER IF EXISTS badge_print_runs_immutable ON badge_print_runs');
        Schema::dropIfExists('badge_print_events');
        Schema::dropIfExists('badge_print_runs');
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_badge_print_append_only()');
    }
};
