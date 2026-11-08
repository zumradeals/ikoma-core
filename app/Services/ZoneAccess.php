<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accès aux zones d'une participation (S06) : une seule règle, utilisée par le contrôle serveur et recopiée sur la tablette.
 * Zones autorisées = zones de la catégorie (category_zone) + zones des habilitations ACTIVES de la participation (non retirées, non suspendues, dans leur période).
 * Un point de contrôle de type « zone » juge cette zone ; un point de type « poste » juge les zones que l'administrateur lui a associées (checkpoint_zones), et ne juge
 * rien tant qu'aucune zone n'est associée. Aucune correspondance n'est codée en dur.
 */
final class ZoneAccess
{
    public function __construct(private readonly Audit $audit) {}

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
        $ids = DB::table('checkpoint_zones')->where('event_id', $eventId)->where('device_id', $checkpoint['id'])->pluck('zone_id')->all();

        return $ids === [] ? null : array_values($ids);
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

    // --- Configuration (administrateur) ---------------------------------------------------------------------------------------------

    /** @return list<object> */
    public function mappings(string $eventId): array
    {
        return DB::table('checkpoint_zones as c')->join('devices as d', 'd.id', '=', 'c.device_id')->join('zones as z', 'z.id', '=', 'c.zone_id')->where('c.event_id', $eventId)
            ->orderBy('d.name')->orderBy('z.name')->get(['c.id', 'c.device_id', DB::raw('coalesce(d.station, d.name) as point'), 'c.zone_id', 'z.name as zone'])->all();
    }

    public function map(string $eventId, string $deviceId, string $zoneId, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($eventId, $deviceId, $zoneId, $actor): void {
            if (! DB::table('devices')->where('id', $deviceId)->where('event_id', $eventId)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['device_id' => 'Point de contrôle inconnu pour cet événement.']);
            }
            if (! DB::table('zones')->where('id', $zoneId)->where('event_id', $eventId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Zone inconnue pour cet événement.']);
            }
            if (DB::table('checkpoint_zones')->where('device_id', $deviceId)->where('zone_id', $zoneId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Cette zone est déjà associée à ce point.']);
            }
            $id = (string) Str::uuid();
            DB::table('checkpoint_zones')->insert(['id' => $id, 'event_id' => $eventId, 'device_id' => $deviceId, 'zone_id' => $zoneId, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('events')->where('id', $eventId)->increment('list_revision');
            $this->audit->append('point.zone_associee', $actor->id, ['correspondance_id' => $id, 'event_id' => $eventId, 'device_id' => $deviceId, 'zone_id' => $zoneId]);
        });
    }

    public function unmap(string $mappingId, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($mappingId, $actor): void {
            $row = DB::table('checkpoint_zones')->where('id', $mappingId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            DB::table('checkpoint_zones')->where('id', $mappingId)->delete();
            DB::table('events')->where('id', $row->event_id)->increment('list_revision');
            $this->audit->append('point.zone_dissociee', $actor->id, ['correspondance_id' => $mappingId, 'event_id' => $row->event_id, 'device_id' => $row->device_id, 'zone_id' => $row->zone_id]);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('zones.gerer') && $actor->can('postes.gerer'), 403);
    }
}
