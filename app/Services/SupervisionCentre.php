<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centre de supervision (S10) : une seule lecture, sans aucune écriture, qui rassemble pour l'accueil du superviseur ce que les services existants savent déjà —
 * postes et leurs dernières opérations, présents, mouvements récents, visites annoncées et retards, véhicules présents, badges à récupérer, refus et incidents,
 * personnes à clôture réservée. Aucune table, aucune règle, aucun droit nouveau : chaque bloc réutilise Pilotage, Supervision, Visits et Vehicles.
 * Les noms de personnes ne sont affichés qu'ici, à la consultation, comme sur les autres écrans du Pilotage.
 */
final class SupervisionCentre
{
    /** Lignes conservées par bloc : les premières alimentent la carte, l'ensemble alimente le panneau de détail. */
    public const LIMIT = 25;

    public function __construct(private readonly Pilotage $pilotage, private readonly Supervision $supervision, private readonly Visits $visits, private readonly Vehicles $vehicles, private readonly SupervisorClosure $closure) {}

    /** @return array<string, mixed> */
    public function build(string $eventId, User $viewer): array
    {
        $expected = $this->visits->expected($eventId);
        $open = array_values(array_filter($expected['rows'], fn (array $r): bool => in_array($r['state'], ['en_retard', 'attendu'], true)));
        $vehicles = $this->vehicles->present($eventId);
        $toReturn = $this->supervision->badgesToReturn($eventId, self::LIMIT);

        return [
            'now' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'postes' => $this->postes($eventId),
            'operations' => $this->operations($eventId),
            'presents' => ['count' => $this->pilotage->presentCount($eventId), 'rows' => $this->pilotage->presentRows($eventId, [], self::LIMIT)],
            'movements' => $this->supervision->movements($eventId, [], 10)->map(fn ($m) => [
                'id' => $m->id, 'occurred_at' => $m->occurred_at, 'type' => $m->type, 'checkpoint' => $m->checkpoint, 'visitor_id' => $m->visitor_id, 'full_name' => $m->full_name,
                'badge_code' => $m->badge_code, 'verdict' => $m->verdict, 'zone_ok' => $m->zone_ok,
            ])->values()->all(),
            'visits' => ['counts' => $expected['counts'], 'late_after_minutes' => $expected['late_after_minutes'], 'rows' => array_slice($open, 0, self::LIMIT)],
            'vehicles' => array_slice($vehicles, 0, self::LIMIT),
            'vehicles_count' => count($vehicles),
            'badges_to_return' => ['count' => $this->badgesToReturnCount($eventId), 'rows' => $toReturn->map(fn ($b) => [
                'id' => $b->id, 'code' => $b->code, 'visitor_id' => $b->visitor_id, 'full_name' => $b->full_name, 'status' => $b->status, 'closed_at' => $b->closed_at, 'assigned_at' => $b->assigned_at,
            ])->values()->all()],
            'refusals' => $this->refusals($eventId),
            'incidents' => $this->supervision->movements($eventId, ['outcome' => 'refuse'], 8)->map(fn ($m) => [
                'id' => $m->id, 'occurred_at' => $m->occurred_at, 'checkpoint' => $m->checkpoint, 'visitor_id' => $m->visitor_id, 'full_name' => $m->full_name, 'badge_code' => $m->badge_code, 'zone_ok' => $m->zone_ok,
            ])->values()->all(),
            'closable' => $viewer->can('cloture.superviseur') ? $this->closable($eventId) : [],
        ];
    }

    /**
     * Postes enrôlés de l'activité : état, dernière synchronisation et dernière opération reçue (type, heure, accepté ou refusé).
     *
     * @return list<array<string, mixed>>
     */
    private function postes(string $eventId): array
    {
        $devices = DB::table('devices')->where('event_id', $eventId)->whereNotNull('token_hash')->orderBy('name')->get(['id', 'name', 'station', 'point_code', 'revoked_at', 'last_synced_at']);
        if ($devices->isEmpty()) {
            return [];
        }
        $ids = $devices->pluck('id')->all();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $last = collect(DB::select("SELECT DISTINCT ON (device_id) device_id, type, received_at, status_code FROM sync_operations WHERE device_id IN ({$marks}) ORDER BY device_id, received_at DESC", $ids))->keyBy('device_id');
        $silentBefore = now()->subMinutes(Pilotage::silentAfterMinutes());

        return $devices->map(function ($d) use ($last, $silentBefore): array {
            $op = $last[$d->id] ?? null;
            $state = $d->revoked_at !== null ? 'revoque' : ($d->last_synced_at === null || Carbon::parse($d->last_synced_at)->lt($silentBefore) ? 'silencieux' : 'actif');

            return ['id' => $d->id, 'name' => $d->station ?? $d->name, 'point_code' => $d->point_code, 'state' => $state, 'last_synced_at' => $d->last_synced_at,
                'last_operation' => $op === null ? null : ['type' => $op->type, 'at' => $op->received_at, 'accepted' => $op->status_code === null || (int) $op->status_code === 200]];
        })->values()->all();
    }

    /**
     * Dernières opérations reçues des postes de l'activité, du plus récent au plus ancien (aucun nom de personne).
     *
     * @return list<array<string, mixed>>
     */
    private function operations(string $eventId): array
    {
        return DB::table('sync_operations as o')->join('devices as d', 'd.id', '=', 'o.device_id')->where('d.event_id', $eventId)->orderByDesc('o.received_at')->limit(10)
            ->get(['o.id', 'o.type', 'o.received_at', 'o.status_code', 'd.name', 'd.station'])
            ->map(fn ($o) => ['id' => $o->id, 'type' => $o->type, 'at' => $o->received_at, 'accepted' => $o->status_code === null || (int) $o->status_code === 200, 'poste' => $o->station ?? $o->name])->values()->all();
    }

    private function badgesToReturnCount(string $eventId): int
    {
        return DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->where('a.event_id', $eventId)->whereNull('a.returned_at')->whereNull('a.cancelled_at')
            ->where(fn ($q) => $q->where('v.status', 'sorti')->orWhereNotNull('v.closed_at'))->count();
    }

    /**
     * Accès refusés : ceux qui attendent encore une décision d'abord, puis les plus récents.
     *
     * @return array{pending: int, rows: list<array<string, mixed>>}
     */
    private function refusals(string $eventId): array
    {
        $base = DB::table('refusals as f')->join('visitors as v', 'v.id', '=', 'f.visitor_id')->where('v.event_id', $eventId);
        $pending = (clone $base)->whereNull('f.decision')->count();
        $rows = $base->orderByRaw('f.decision IS NOT NULL')->orderByDesc('f.refused_at')->limit(8)->get(['f.id', 'f.reason', 'f.refused_at', 'f.decision', 'v.id as visitor_id', 'v.full_name'])
            ->map(fn ($r) => ['id' => $r->id, 'reason' => $r->reason, 'refused_at' => $r->refused_at, 'decision' => $r->decision, 'visitor_id' => $r->visitor_id, 'full_name' => $r->full_name])->values()->all();

        return ['pending' => $pending, 'rows' => $rows];
    }

    /**
     * Personnes dont la catégorie est à clôture réservée et dont la participation est encore ouverte (présentes ou sorties) : cibles de la clôture du superviseur.
     *
     * @return list<array<string, mixed>>
     */
    private function closable(string $eventId): array
    {
        return DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->where('v.event_id', $eventId)->where('c.closure_supervisor_only', true)
            ->whereNull('v.closed_at')->whereNull('v.withdrawn_at')->whereIn('v.status', ['present', 'sorti'])->orderBy('v.full_name')->limit(self::LIMIT)
            ->selectRaw("v.id, v.full_name, v.status, c.name AS category_name, c.color,
                (SELECT b.code FROM badge_assignments a JOIN badges b ON b.id = a.badge_id WHERE a.visitor_id = v.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL LIMIT 1) AS badge_code,
                (SELECT count(*) FROM vehicles x WHERE x.visitor_id = v.id AND x.status = 'present') AS vehicles_present")
            ->get()->filter(fn ($r): bool => $this->closure->offer($r->id)['possible'] ?? false)
            ->map(fn ($r) => ['id' => $r->id, 'full_name' => $r->full_name, 'status' => $r->status, 'category' => $r->category_name, 'color' => $r->color, 'badge_code' => $r->badge_code, 'vehicles_present' => (int) $r->vehicles_present])->values()->all();
    }
}
