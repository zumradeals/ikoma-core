<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accès aux zones d'une participation (S06) : une seule règle, utilisée par le contrôle serveur et recopiée sur la tablette.
 * Zones autorisées = zones de la catégorie (category_zone) + zones des habilitations ACTIVES de la participation (non retirées, non suspendues, dans leur période).
 * Un point de contrôle de type « zone » juge cette zone ; un point de type « poste » juge les zones du site que l'administrateur lui a associées (checkpoint_site_zones, dans Site et accès) et que l'activité réutilise, et ne juge
 * rien tant qu'aucune zone n'est associée. Aucune correspondance n'est codée en dur.
 */
final class ZoneAccess
{
    /** @return Collection<int, \stdClass> zones (id, name) accessibles à la participation à l'instant donné, triées par nom */
    public function zonesFor(string $visitorId, ?string $categoryId, ?Carbon $at = null): Collection
    {
        $at ??= now();
        $byCategory = DB::table('category_zone as cz')->join('zones as z', 'z.id', '=', 'cz.zone_id')->where('cz.category_id', $categoryId)->select('z.id', 'z.name');
        $byEntitlement = DB::table('participation_entitlements as e')->join('zones as z', 'z.id', '=', 'e.zone_id')->where('e.visitor_id', $visitorId)
            ->whereNull('e.revoked_at')->whereNull('e.suspended_at')->where('e.valid_from', '<=', $at)->where('e.valid_until', '>=', $at)->select('z.id', 'z.name');

        return $byCategory->union($byEntitlement)->get()->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Zones jugées par un point de contrôle, ou null si le point ne juge aucune zone.
     *
     * @param  array{type:string,id:?string}  $checkpoint
     * @return list<string>|null
     */
    public function judgedZones(string $eventId, array $checkpoint): ?array
    {
        if ($checkpoint['type'] === 'zone') {
            return [(string) $checkpoint['id']];
        }
        // La correspondance est portée par le POSTE : la tablette (point de type « poste ») juge les zones du site gardées par le poste auquel elle est affectée.
        $ids = $this->siteZonesJudged($eventId, (string) $checkpoint['id']);

        return $ids === [] ? null : array_values(array_unique($ids));
    }

    /**
     * Zones DU SITE gardées par le poste de cette tablette ET utilisées par l'activité (par une catégorie ou par une habilitation de participant) :
     * une zone du site que l'activité ne réutilise pas ne change rien au jugement de ses visiteurs.
     *
     * @return list<string>
     */
    public function siteZonesJudged(string $eventId, string $deviceId): array
    {
        $used = DB::table('category_zone')->where('event_id', $eventId)->select('zone_id')->union(DB::table('participation_entitlements')->where('event_id', $eventId)->select('zone_id'));

        /** @var list<string> $ids */
        $ids = DB::table('checkpoint_site_zones as s')->join('devices as d', 'd.checkpoint_id', '=', 's.checkpoint_id')->where('d.id', $deviceId)->whereIn('s.zone_id', $used)->pluck('s.zone_id')->all();

        return $ids;
    }

    /**
     * @param  list<string>|null  $judged
     * @param  Collection<int, \stdClass>  $allowed
     */
    public static function decide(?array $judged, Collection $allowed): ?bool
    {
        if ($judged === null) {
            return null;
        }

        // Un point qui garde plusieurs zones est franchi si la participation est autorisée dans AU MOINS une d'elles.
        return $allowed->pluck('id')->intersect($judged)->isNotEmpty();
    }
}
