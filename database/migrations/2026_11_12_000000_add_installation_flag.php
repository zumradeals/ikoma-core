<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Lot L12 : l'assistant d'initialisation se verrouille définitivement une fois l'installation terminée. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insert(['key' => 'installation_terminee', 'value' => '0']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'installation_terminee')->delete();
    }
};
