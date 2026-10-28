<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('recipient_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('visitor_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('requester_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('device_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 30);
            $t->string('message', 180);
            $t->string('dedupe_key')->unique();
            $t->timestampTz('read_at')->nullable();
            $t->string('decision', 20)->nullable();
            $t->string('reason', 500)->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampTz('escalated_at')->nullable();
            $t->timestampsTz(6);
            $t->index(['recipient_id', 'created_at']);
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_valid CHECK (type IN ('arrivee','non_liste','hors_periode'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_decision_valid CHECK (decision IN ('autorise','refuse'))");
        Schema::table('events', fn (Blueprint $t) => $t->unsignedBigInteger('list_revision')->default(0));
        DB::statement('ALTER TABLE visitors ALTER COLUMN updated_at TYPE timestamp(6) with time zone');
        Schema::table('visitors', fn (Blueprint $t) => $t->timestampTz('withdrawn_at')->nullable());
        DB::table('settings')->insertOrIgnore(['key' => 'hote_delai_reponse_minutes', 'value' => '2']);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('list_revision'));
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn('withdrawn_at'));
        DB::table('settings')->where('key', 'hote_delai_reponse_minutes')->delete();
    }
};
