<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', fn (Blueprint $t) => $t->text('value')->change());
        Schema::table('devices', fn (Blueprint $t) => $t->bigInteger('sync_seq')->default(0));
        Schema::table('events', function (Blueprint $t) {
            $t->timestampTz('anonymized_at')->nullable();
            $t->jsonb('retention_summary')->nullable();
        });
        Schema::table('sync_operations', fn (Blueprint $t) => $t->index(['device_id', 'seq']));
        DB::statement('UPDATE devices SET sync_seq = COALESCE((SELECT max(seq) FROM sync_operations WHERE device_id = devices.id), 0)');
        foreach (['visiteurs' => 30, 'evenements' => 90, 'photos' => 7, 'synchronisations' => 30, 'alertes' => 30, 'notifications' => 7, 'audit' => 365] as $type => $days) {
            DB::table('settings')->insertOrIgnore(['key' => 'conservation_'.$type.'_jours', 'value' => (string) $days]);
        }
        DB::table('settings')->insertOrIgnore(['key' => 'conservation_validee', 'value' => '0']);
        DB::table('settings')->insertOrIgnore(['key' => 'journal_export_max_lignes', 'value' => '10000']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'audit_checkpoint')->delete();
        Schema::table('settings', fn (Blueprint $t) => $t->string('value')->change());
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('sync_seq'));
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn(['anonymized_at', 'retention_summary']));
        Schema::table('sync_operations', fn (Blueprint $t) => $t->dropIndex(['device_id', 'seq']));
        DB::table('settings')->where('key', 'like', 'conservation_%')->orWhere('key', 'journal_export_max_lignes')->delete();
    }
};
