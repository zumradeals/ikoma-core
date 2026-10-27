<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('site')->nullable();
            $t->string('status')->nullable();
            $t->timestampsTz();
        });
        Schema::create('zones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
            $t->unique(['event_id', 'name']);
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->char('color', 7);
            $t->boolean('priority_circuit')->default(false);
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
            $t->unique(['event_id', 'name']);
        });
        Schema::create('category_zone', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('event_id');
            $t->uuid('category_id');
            $t->uuid('zone_id');
            $t->timestampsTz();
            $t->unique(['category_id', 'zone_id']);
            $t->foreign(['category_id', 'event_id'])->references(['id', 'event_id'])->on('categories')->restrictOnDelete();
            $t->foreign(['zone_id', 'event_id'])->references(['id', 'event_id'])->on('zones')->restrictOnDelete();
        });
        Schema::create('hosts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 160);
            $t->string('service')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('station', 120)->nullable();
            $t->char('token_hash', 64)->nullable()->unique();
            $t->timestampTz('last_synced_at')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
        });
        Schema::create('badge_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('margin')->default(0);
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
        });
        Schema::create('groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('bus_number', 60)->nullable();
            $t->foreignUuid('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('badge_batch_id')->nullable();
            $t->foreign(['badge_batch_id', 'event_id'])->references(['id', 'event_id'])->on('badge_batches')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('file_reference')->nullable();
            $t->string('status')->nullable();
            $t->jsonb('summary')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
        });
        Schema::create('visitors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('full_name', 160);
            $t->string('organisation', 160)->nullable();
            $t->uuid('host_id')->nullable();
            $t->uuid('category_id');
            $t->uuid('group_id')->nullable();
            $t->uuid('import_batch_id')->nullable();
            $t->timestampTz('valid_from');
            $t->timestampTz('valid_until');
            $t->string('source', 20);
            $t->string('status')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
            $t->unique(['id', 'group_id']);
            $t->foreign(['category_id', 'event_id'])->references(['id', 'event_id'])->on('categories')->restrictOnDelete();
            $t->foreign(['host_id', 'event_id'])->references(['id', 'event_id'])->on('hosts')->restrictOnDelete();
            $t->foreign(['group_id', 'event_id'])->references(['id', 'event_id'])->on('groups')->restrictOnDelete();
            $t->foreign(['import_batch_id', 'event_id'])->references(['id', 'event_id'])->on('import_batches')->restrictOnDelete();
            $t->index(['event_id', 'category_id']);
        });
        Schema::create('group_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('group_id')->constrained()->restrictOnDelete();
            $t->uuid('visitor_id');
            $t->timestampTz('boarded_at')->nullable();
            $t->timestampTz('arrived_at')->nullable();
            $t->timestampsTz();
            $t->unique(['group_id', 'visitor_id']);
            $t->foreign(['visitor_id', 'group_id'])->references(['id', 'group_id'])->on('visitors')->restrictOnDelete();
        });
        Schema::create('badges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->char('qr_token', 64)->unique();
            $t->string('status', 20)->default('Disponible');
            $t->uuid('badge_batch_id');
            $t->uuid('reserved_device_id')->nullable();
            $t->foreign(['badge_batch_id', 'event_id'])->references(['id', 'event_id'])->on('badge_batches')->restrictOnDelete();
            $t->foreign(['reserved_device_id', 'event_id'])->references(['id', 'event_id'])->on('devices')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['id', 'event_id']);
            $t->index(['event_id', 'status']);
        });
        Schema::create('badge_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->uuid('badge_id');
            $t->uuid('visitor_id');
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->uuid('device_id')->nullable();
            $t->timestampTz('assigned_at');
            $t->timestampTz('returned_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestampsTz();
            $t->foreign(['badge_id', 'event_id'])->references(['id', 'event_id'])->on('badges')->restrictOnDelete();
            $t->foreign(['visitor_id', 'event_id'])->references(['id', 'event_id'])->on('visitors')->restrictOnDelete();
            $t->foreign(['device_id', 'event_id'])->references(['id', 'event_id'])->on('devices')->restrictOnDelete();
        });
        Schema::create('identity_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('visitor_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('checked_at');
            $t->timestampsTz();
        });
        Schema::create('photos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('visitor_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('file_reference');
            $t->timestampTz('taken_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('access_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->uuid('visitor_id');
            $t->uuid('badge_id')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('type');
            $t->string('checkpoint')->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampsTz();
            $t->foreign(['visitor_id', 'event_id'])->references(['id', 'event_id'])->on('visitors')->restrictOnDelete();
            $t->foreign(['badge_id', 'event_id'])->references(['id', 'event_id'])->on('badges')->restrictOnDelete();
        });
        Schema::create('refusals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('visitor_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->text('supervisor_decision')->nullable();
            $t->timestampTz('refused_at');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE badges ADD CONSTRAINT badges_status_valid CHECK (status IN ('Disponible','Associé','Restitué','Annulé','Clos'))");
        DB::statement("ALTER TABLE visitors ADD CONSTRAINT visitors_source_valid CHECK (source IN ('listé','non listé'))");
        DB::statement('ALTER TABLE visitors ADD CONSTRAINT visitors_valid_dates CHECK (valid_until >= valid_from)');
        DB::statement('ALTER TABLE events ADD CONSTRAINT events_valid_dates CHECK (ends_at >= starts_at)');
        DB::statement('CREATE UNIQUE INDEX badge_assignments_one_active ON badge_assignments (badge_id) WHERE returned_at IS NULL AND cancelled_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_normalize(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, $1)) $$;
CREATE INDEX visitors_name_trgm ON visitors USING gin (public.ikoma_normalize(full_name) public.gin_trgm_ops);
SQL);
    }

    public function down(): void
    {
        foreach (['refusals', 'access_events', 'photos', 'identity_checks', 'badge_assignments', 'badges', 'group_members', 'visitors', 'import_batches', 'groups', 'badge_batches', 'devices', 'hosts', 'category_zone', 'categories', 'zones', 'events'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_normalize(text)');
    }
};
