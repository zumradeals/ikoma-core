<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Profil de sécurité (décision du dirigeant du 28 octobre 2026) : `standard` pour la démonstration et les essais, `renforce` pour toute donnée réelle.
 * La migration pose toujours `standard` : l'environnement (APP_ENV) ne dit pas si les données sont réelles (le serveur de démonstration tourne en production avec des
 * données fictives). L'installation de production (lot L12) doit poser `renforce` explicitement, et l'écran de l'administrateur le rappelle tant que ce n'est pas fait.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insert(['key' => 'profil_securite', 'value' => 'standard']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'profil_securite')->delete();
    }
};
