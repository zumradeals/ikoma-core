<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zones qu'une activité peut utiliser : ses propres zones (ancien chemin) et toutes les zones du site (sans événement).
 * Un seul endroit décrit cette règle (docs/ailct/V1-ZONES-DU-SITE.md) : les lecteurs et les validations passent par ici.
 */
final class ActivityZones
{
    public static function query(string $eventId, string $alias = 'zones'): Builder
    {
        $prefix = $alias === 'zones' ? '' : $alias.'.';

        return DB::table($alias === 'zones' ? 'zones' : 'zones as '.$alias)->where(fn ($q) => $q->where($prefix.'event_id', $eventId)->orWhereNull($prefix.'event_id'));
    }

    /**
     * Une zone d'accueil du site doit exister (les visiteurs non listés n'ont accès qu'à elle). Créée une fois, sans rien changer si elle existe déjà.
     * Une activité ne crée plus sa propre zone « Accueil ».
     */
    public static function ensureSiteReception(): void
    {
        if (DB::table('zones')->whereNull('event_id')->where('est_accueil', true)->exists()) {
            return;
        }
        $site = (string) DB::table('sites')->orderBy('created_at')->orderBy('id')->value('id');
        $existing = DB::table('zones')->whereNull('event_id')->where('site_id', $site)->where('name', 'Accueil')->value('id');
        if ($existing !== null) {
            DB::table('zones')->where('id', $existing)->update(['est_accueil' => true, 'updated_at' => now()]);

            return;
        }
        DB::table('zones')->insert(['id' => (string) Str::uuid(), 'event_id' => null, 'site_id' => $site, 'name' => 'Accueil', 'est_accueil' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function allows(string $eventId, string $zoneId): bool
    {
        return self::query($eventId)->where('id', $zoneId)->exists();
    }
}
