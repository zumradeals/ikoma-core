<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $t) {
            $t->char('enrollment_hash', 64)->nullable()->unique();
            $t->timestampTz('enrollment_expires_at')->nullable();
            $t->timestampTz('enrolled_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('wipe_acknowledged_at')->nullable();
        });
        Schema::table('sync_operations', function (Blueprint $t) {
            $t->unsignedBigInteger('seq')->nullable();
            $t->timestampTz('client_time')->nullable();
            $t->uuid('visitor_id')->nullable();
            $t->index('visitor_id');
        });
        Schema::create('sync_conflicts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operation_id')->nullable()->unique();
            $t->foreign('operation_id')->references('id')->on('sync_operations')->restrictOnDelete();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $t->uuid('agent_id')->nullable();
            $t->uuid('visitor_id')->nullable();
            $t->string('operation_type', 40)->nullable();
            $t->string('kind', 20);
            $t->string('reason', 60);
            $t->jsonb('details')->nullable();
            $t->string('status', 20)->default('ouvert');
            $t->timestampTz('resolved_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE sync_conflicts ADD CONSTRAINT sync_conflicts_kind_valid CHECK (kind IN ('conflit','horloge'))");
        DB::statement("ALTER TABLE sync_conflicts ADD CONSTRAINT sync_conflicts_status_valid CHECK (status IN ('ouvert','resolu'))");
        DB::table('settings')->insert([['key' => 'poste_inactivite_minutes', 'value' => '5'], ['key' => 'poste_ecart_horloge_secondes', 'value' => '300'], ['key' => 'poste_echecs_pin_max', 'value' => '15']]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['poste_inactivite_minutes', 'poste_ecart_horloge_secondes', 'poste_echecs_pin_max'])->delete();
        Schema::dropIfExists('sync_conflicts');
        Schema::table('sync_operations', function (Blueprint $t) {
            $t->dropIndex(['visitor_id']);
            $t->dropColumn(['seq', 'client_time', 'visitor_id']);
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->dropColumn(['enrollment_hash', 'enrollment_expires_at', 'enrolled_at', 'revoked_at', 'wipe_acknowledged_at']);
        });
    }
};
