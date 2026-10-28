<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit global C1 : une alerte critique ou haute que personne ne prend en compte remonte à l'administrateur après un délai réglable.
 * Aucun canal externe (e-mail, SMS, notification poussée) : la décision L10 les exclut jusqu'à l'avis du client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->timestampTz('escalated_at')->nullable());
        DB::table('settings')->insert(['key' => 'alerte_escalade_minutes', 'value' => '5']); // valeur de départ proposée, à confirmer avec le client
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'alerte_escalade_minutes')->delete();
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('escalated_at'));
    }
};
