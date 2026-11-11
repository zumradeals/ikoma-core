<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    public static function allows(string $eventId, string $zoneId): bool
    {
        return self::query($eventId)->where('id', $zoneId)->exists();
    }
}
