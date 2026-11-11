<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zones qu'une activité peut utiliser : toutes les zones du site (une zone n'appartient jamais à une activité).
 * Un seul endroit décrit cette règle (docs/ailct/V1-ZONES-DU-SITE.md) : les lecteurs et les validations passent par ici.
 */
final class ActivityZones
{
    public static function query(string $alias = 'zones'): Builder
    {
        return DB::table($alias === 'zones' ? 'zones' : 'zones as '.$alias);
    }

    /**
     * Une zone d'accueil du site doit exister (les visiteurs non listés n'ont accès qu'à elle). Créée une fois, sans rien changer si elle existe déjà.
     * Une activité ne crée plus sa propre zone « Accueil ».
     */
    public static function ensureSiteReception(): void
    {
        if (DB::table('zones')->where('est_accueil', true)->exists()) {
            return;
        }
        $site = (string) DB::table('sites')->orderBy('created_at')->orderBy('id')->value('id');
        $existing = DB::table('zones')->where('site_id', $site)->where('name', 'Accueil')->value('id');
        if ($existing !== null) {
            DB::table('zones')->where('id', $existing)->update(['est_accueil' => true, 'updated_at' => now()]);

            return;
        }
        DB::table('zones')->insert(['id' => (string) Str::uuid(), 'site_id' => $site, 'name' => 'Accueil', 'est_accueil' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Retrouve (ou crée) une zone du site par son nom exact : les jeux d'essai réutilisent les mêmes zones d'un lancement à l'autre.
     * Une zone d'accueil ne perd jamais ce statut ici.
     */
    public static function siteZone(string $name, bool $reception = false): string
    {
        $site = (string) DB::table('sites')->orderBy('created_at')->orderBy('id')->value('id');
        $row = DB::table('zones')->where('site_id', $site)->where('name', $name)->first(['id', 'est_accueil']);
        if ($row !== null) {
            if ($reception && ! $row->est_accueil) {
                DB::table('zones')->where('id', $row->id)->update(['est_accueil' => true, 'updated_at' => now()]);
            }

            return (string) $row->id;
        }
        $id = (string) Str::uuid();
        DB::table('zones')->insert(['id' => $id, 'site_id' => $site, 'name' => $name, 'est_accueil' => $reception, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public static function allows(string $zoneId): bool
    {
        return self::query()->where('id', $zoneId)->exists();
    }
}
