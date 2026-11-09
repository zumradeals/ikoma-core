<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Centre de supervision (S07) : lectures seules au-dessus des données existantes (événements d'accès, attributions de badges, habilitations). Aucune table propre,
 * aucune écriture : le superviseur agit par les routes existantes (alertes, litiges, annulation de badge) et ne modifie jamais une habilitation.
 */
final class Supervision
{
    public const PER_PAGE = 30;

    public const TYPES = ['entree', 'sortie', 'controle', 'correction', 'embarquement', 'debarquement', 'vehicule_entree', 'vehicule_sortie'];

    /**
     * Journal des mouvements physiques d'une activité : entrées, sorties, contrôles (valides ou refusés) et corrections, du plus récent au plus ancien.
     *
     * @param  array{q?: ?string, type?: ?string, point?: ?string, outcome?: ?string, from?: ?string, until?: ?string}  $filters
     * @return Collection<int, \stdClass>
     */
    public function movements(string $eventId, array $filters, int $limit = self::PER_PAGE, int $offset = 0): Collection
    {
        return $this->movementQuery($eventId, $filters)->orderByDesc('e.occurred_at')->orderByDesc('e.id')->offset($offset)->limit($limit)
            ->get(['e.id', 'e.occurred_at', 'e.type', 'e.checkpoint', 'e.visitor_id', 'v.person_id', 'v.full_name', 'b.code as badge_code', 'e.corrige_id',
                DB::raw("e.details->>'verdict' as verdict"), DB::raw("e.details->>'zone_ok' as zone_ok"), DB::raw("e.details->>'direction' as direction"),
                DB::raw('EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id) as corrected')]);
    }

    /** @param array{q?: ?string, type?: ?string, point?: ?string, outcome?: ?string, from?: ?string, until?: ?string} $filters */
    public function movementCount(string $eventId, array $filters): int
    {
        return $this->movementQuery($eventId, $filters)->count();
    }

    /** @return list<string> */
    public function points(string $eventId): array
    {
        return DB::table('access_events')->where('event_id', $eventId)->whereNotNull('checkpoint')->distinct()->orderBy('checkpoint')->pluck('checkpoint')->all();
    }

    /**
     * Badges encore attribués à une personne sortie ou dont la participation est clôturée : à récupérer (sortie physique ≠ restitution).
     *
     * @return Collection<int, \stdClass>
     */
    public function badgesToReturn(string $eventId, int $limit = 200): Collection
    {
        return DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->join('badges as b', 'b.id', '=', 'a.badge_id')
            ->where('a.event_id', $eventId)->whereNull('a.returned_at')->whereNull('a.cancelled_at')
            ->where(fn ($q) => $q->where('v.status', 'sorti')->orWhereNotNull('v.closed_at'))
            ->orderBy('v.updated_at')->limit($limit)->get(['a.id', 'b.id as badge_id', 'b.code', 'v.id as visitor_id', 'v.full_name', 'v.status', 'v.closed_at', 'a.assigned_at']);
    }

    /**
     * Habilitations de participation à surveiller : actives, suspendues, ou exceptionnelles récemment terminées. Lecture seule.
     *
     * @return Collection<int, \stdClass>
     */
    public function entitlements(string $eventId, int $limit = 200): Collection
    {
        return DB::table('participation_entitlements as e')->join('visitors as v', 'v.id', '=', 'e.visitor_id')->join('zones as z', 'z.id', '=', 'e.zone_id')->where('e.event_id', $eventId)
            ->whereNull('e.revoked_at')->where('e.valid_until', '>=', now()->subHours(6))
            ->orderByRaw("CASE WHEN e.kind = 'exceptionnelle' THEN 0 ELSE 1 END")->orderBy('e.valid_until')->limit($limit)
            ->get(['e.id', 'v.id as visitor_id', 'v.full_name', 'z.name as zone', 'e.kind', 'e.reason', 'e.valid_from', 'e.valid_until', 'e.suspended_at', 'e.suspension_reason'])
            ->map(function ($row) {
                $now = now();
                if ($row->suspended_at !== null) {
                    $row->state = 'suspendue';
                } elseif (Carbon::parse($row->valid_until)->lt($now)) {
                    $row->state = 'expiree';
                } elseif (Carbon::parse($row->valid_from)->gt($now)) {
                    $row->state = 'a_venir';
                } else {
                    $row->state = 'active';
                }

                return $row;
            });
    }

    /**
     * @param  array{q?: ?string, type?: ?string, point?: ?string, outcome?: ?string, from?: ?string, until?: ?string}  $filters
     */
    private function movementQuery(string $eventId, array $filters): Builder
    {
        $query = DB::table('access_events as e')->leftJoin('visitors as v', 'v.id', '=', 'e.visitor_id')->leftJoin('badges as b', 'b.id', '=', 'e.badge_id')
            ->where('e.event_id', $eventId)->whereIn('e.type', self::TYPES);
        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['q']).'%';
            $query->whereRaw('public.ikoma_normalize(v.full_name) LIKE public.ikoma_normalize(?)', [$like]);
        }
        if (! empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
            $query->where('e.type', $filters['type']);
        }
        if (! empty($filters['point'])) {
            $query->where('e.checkpoint', $filters['point']);
        }
        if (($filters['outcome'] ?? '') === 'refuse') {
            $query->where('e.type', 'controle')->where(fn ($q) => $q->whereRaw("coalesce(e.details->>'verdict', '') <> 'valide'")->orWhereRaw("e.details->>'zone_ok' = 'false'"));
        } elseif (($filters['outcome'] ?? '') === 'valide') {
            $query->where('e.type', 'controle')->whereRaw("e.details->>'verdict' = 'valide'")->whereRaw("coalesce(e.details->>'zone_ok', 'true') <> 'false'");
        }
        if (! empty($filters['from'])) {
            $query->where('e.occurred_at', '>=', $filters['from']);
        }
        if (! empty($filters['until'])) {
            $query->where('e.occurred_at', '<=', $filters['until']);
        }

        return $query;
    }
}
