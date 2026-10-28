<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $t) {
            $t->uuid('id')->primary(); // identifiant d'opération généré par la tablette (en-tête X-Operation-Id)
            $t->foreignUuid('device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $t->string('type', 40);
            $t->char('payload_hash', 64);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->jsonb('result')->nullable();
            $t->timestampTz('received_at');
            $t->timestampTz('processed_at')->nullable();
            $t->timestampsTz();
            $t->index(['device_id', 'received_at']);
        });
        Schema::table('visitors', function (Blueprint $t) {
            $t->string('host_validation', 20)->nullable();
        });
        DB::statement("ALTER TABLE visitors ADD CONSTRAINT visitors_host_validation_valid CHECK (host_validation IN ('en_attente','accepte','refuse'))");
        Schema::table('zones', function (Blueprint $t) {
            $t->boolean('est_accueil')->default(false);
        });
        Schema::table('access_events', function (Blueprint $t) {
            $t->jsonb('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('access_events', fn (Blueprint $t) => $t->dropColumn('details'));
        Schema::table('zones', fn (Blueprint $t) => $t->dropColumn('est_accueil'));
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT visitors_host_validation_valid');
        Schema::table('visitors', fn (Blueprint $t) => $t->dropColumn('host_validation'));
        Schema::dropIfExists('sync_operations');
    }
};
