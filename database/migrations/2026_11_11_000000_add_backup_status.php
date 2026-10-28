<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L12 : état des sauvegardes (écrit par scripts/backup.sh) et seuil d'alerte réglable. L'alerte n'apparaît que si l'installation de production
 * a activé le suivi (`sauvegarde_active` = 1) : un poste de développement ou la démonstration n'ont pas de sauvegarde à surveiller.
 */
return new class extends Migration
{
    private const KEYS = [
        'sauvegarde_active' => '0',
        'sauvegarde_alerte_heures' => '26',
        'sauvegarde_derniere_reussite' => '',
        'sauvegarde_dernier_resultat' => '',
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
