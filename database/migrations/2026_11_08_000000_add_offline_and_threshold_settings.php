<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seuils d'exploitation rendus réglables dans Administration > Postes (audit global F2, C1 et réglages approuvés par le dirigeant) :
 * rien de tout cela ne reste en constante dans le code. Valeurs de départ proposées, à confirmer avec le client.
 */
return new class extends Migration
{
    private const KEYS = [
        'poste_delai_appel_minutes' => '15',        // sans réseau depuis N minutes, l'agent est invité à prévenir le superviseur
        'poste_hors_reseau_max_heures' => '12',     // sans synchronisation depuis N heures, la tablette exige le réseau (0 = désactivé)
        'poste_badges_bas_pourcent' => '10',        // « il reste peu de badges » sous ce pourcentage du lot du poste…
        'poste_badges_bas_minimum' => '5',          // …ou sous ce nombre de badges
        'pilotage_poste_silencieux_minutes' => '60', // tablette sans nouvelles depuis N minutes (écran du superviseur et tableau de l'administrateur)
    ];

    public function up(): void
    {
        DB::table('settings')->insert(array_map(fn (string $key, string $value) => ['key' => $key, 'value' => $value], array_keys(self::KEYS), self::KEYS));
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::KEYS))->delete();
    }
};
