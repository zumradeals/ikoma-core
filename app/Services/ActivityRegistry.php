<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Registre des activités (V1, chantier Activités) : LECTURE SEULE.
 *
 * Aucune table, aucun statut ni aucune règle n'est ajouté. Les participations (`visitors`), leurs habilitations (`participation_entitlements`),
 * les badges (`badge_assignments`), les groupes (`groups`) et le journal (`audit_logs`) sont seulement composés pour être consultés.
 * Les opérations (inscription, clôture, création, modification, clôture de l'activité) passent par les routes et services existants.
 *
 * La « phase » d'une activité est DÉRIVÉE de ses dates et de son statut enregistré (clos / archive) : elle n'est jamais stockée.
 * Les habilitations permanentes ne sont ni lues ni modifiées ici : elles appartiennent au profil permanent de la personne.
 */
final class ActivityRegistry
{
    public const PER_PAGE = 15;

    private const OPEN = "(e.status IS NULL OR e.status NOT IN ('clos', 'archive')) AND e.anonymized_at IS NULL";

    private function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s+00');
    }

    /**
     * Liste des activités avec effectifs et phase dérivée (tous les états, pour le filtrage côté écran).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(bool $archives): array
    {
        $now = $this->now();

        return DB::table('events as e')
            ->when($archives, fn ($q) => $q->where('e.status', 'archive'), fn ($q) => $q->where(fn ($w) => $w->whereNull('e.status')->orWhere('e.status', '<>', 'archive')))
            ->orderByDesc('e.starts_at')
            ->get(['e.id', 'e.name', 'e.site', 'e.kind', 'e.starts_at', 'e.ends_at', 'e.status', 'e.is_permanent',
                DB::raw('(SELECT count(*) FROM visitors v WHERE v.event_id = e.id AND v.holder_id IS NULL AND v.withdrawn_at IS NULL) AS participants'),
                DB::raw('(SELECT count(*) FROM visitors v WHERE v.event_id = e.id AND v.holder_id IS NULL AND v.withdrawn_at IS NULL AND v.closed_at IS NULL) AS participants_actifs'),
                DB::raw("CASE WHEN e.status = 'archive' THEN 'archivee' WHEN e.status = 'clos' THEN 'cloturee' WHEN e.is_permanent THEN 'permanent' WHEN e.starts_at > '{$now}' THEN 'a_venir' WHEN e.ends_at <= '{$now}' THEN 'terminee' ELSE 'en_cours' END AS phase"),
            ])->map(fn (object $r): array => (array) $r)->all();
    }

    /**
     * Indicateurs calculés sur les données réelles (activités ordinaires seulement ; le contexte permanent n'en fait pas partie).
     *
     * @return array{en_cours: int, participants_actifs: int, a_venir: int, cloturees: int}
     */
    public function kpis(): array
    {
        $now = $this->now();
        $open = fn () => DB::table('events as e')->where('e.is_permanent', false)->whereRaw(self::OPEN);

        return [
            'en_cours' => $open()->where('e.starts_at', '<=', $now)->where('e.ends_at', '>', $now)->count(),
            'participants_actifs' => (int) DB::table('visitors as v')->join('events as e', 'e.id', '=', 'v.event_id')->where('e.is_permanent', false)->whereRaw(self::OPEN)
                ->whereNull('v.holder_id')->whereNull('v.closed_at')->whereNull('v.withdrawn_at')->count(),
            'a_venir' => $open()->where('e.starts_at', '>', $now)->count(),
            'cloturees' => DB::table('events as e')->where('e.is_permanent', false)->where(fn ($w) => $w->whereIn('e.status', ['clos', 'archive'])->orWhereNotNull('e.anonymized_at'))->count(),
        ];
    }

    /**
     * Fiche d'une activité : identité, effectifs, catégories de participation, groupes et cars, accès et badges (synthèse), journal.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $eventId, bool $withJournal): ?array
    {
        $event = DB::table('events')->where('id', $eventId)->first(['id', 'name', 'site', 'kind', 'starts_at', 'ends_at', 'status', 'is_permanent', 'anonymized_at', 'list_revision']);
        if ($event === null) {
            return null;
        }
        $closed = in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null;
        $base = fn () => DB::table('visitors as v')->where('v.event_id', $eventId)->whereNull('v.holder_id');
        $now = $this->now();

        $counts = $base()->selectRaw('count(*) FILTER (WHERE v.withdrawn_at IS NULL) AS total, count(*) FILTER (WHERE v.withdrawn_at IS NULL AND v.closed_at IS NULL) AS actifs, '
            ."count(*) FILTER (WHERE v.withdrawn_at IS NULL AND v.closed_at IS NULL AND v.status = 'present') AS presents, count(*) FILTER (WHERE v.closed_at IS NOT NULL) AS clotures")->first();
        $categories = DB::table('categories as c')->leftJoin('visitors as v', fn ($j) => $j->on('v.category_id', '=', 'c.id')->whereNull('v.holder_id')->whereNull('v.withdrawn_at'))
            ->where('c.event_id', $eventId)->groupBy('c.id', 'c.name')->orderBy('c.name')->get(['c.id', 'c.name', DB::raw('count(v.id) AS participants')])
            ->map(fn (object $c): array => ['id' => $c->id, 'name' => $c->name, 'participants' => (int) $c->participants])->all();
        $groups = DB::table('groups as g')->leftJoin('visitors as v', fn ($j) => $j->on('v.group_id', '=', 'g.id')->whereNull('v.withdrawn_at'))->leftJoin('users as u', 'u.id', '=', 'g.supervisor_id')
            ->where('g.event_id', $eventId)->groupBy('g.id', 'g.name', 'g.bus_number', 'u.name')->orderBy('g.name')->get(['g.id', 'g.name', 'g.bus_number', 'u.name as supervisor', DB::raw('count(v.id) AS members')])
            ->map(fn (object $g): array => ['id' => $g->id, 'name' => $g->name, 'bus_number' => $g->bus_number, 'supervisor' => $g->supervisor, 'members' => (int) $g->members])->all();

        // Habilitations de participation EFFECTIVES par zone (ni retirées, ni suspendues, dans leur période, participation ouverte) : lecture du moteur d'activité seulement.
        $zones = DB::table('zones as z')->leftJoin('participation_entitlements as pe', function ($j) use ($now): void {
            $j->on('pe.zone_id', '=', 'z.id')->whereNull('pe.revoked_at')->whereNull('pe.suspended_at')->where('pe.valid_from', '<=', $now)->where('pe.valid_until', '>', $now)
                ->whereIn('pe.visitor_id', DB::table('visitors')->whereNull('closed_at')->whereNull('withdrawn_at')->select('id'));
        })->where('z.event_id', $eventId)->groupBy('z.id', 'z.name')->orderBy('z.name')->get(['z.id', 'z.name', DB::raw('count(pe.id) AS habilitations')])
            ->map(fn (object $z): array => ['id' => $z->id, 'name' => $z->name, 'habilitations' => (int) $z->habilitations])->all();
        $badges = DB::table('badge_assignments')->where('event_id', $eventId)->selectRaw('count(*) FILTER (WHERE returned_at IS NULL AND cancelled_at IS NULL) AS attribues, count(*) FILTER (WHERE returned_at IS NOT NULL) AS restitues')->first();
        $vehicles = (int) DB::table('vehicles as ve')->join('visitors as v', 'v.id', '=', 've.visitor_id')->where('v.event_id', $eventId)->whereIn('ve.status', ['annonce', 'present'])->count();

        $journal = [];
        if ($withJournal) {
            $journal = DB::table('audit_logs')->whereRaw('details LIKE ?', ['%"event_id":"'.$eventId.'"%'])->orWhereRaw('details LIKE ?', ['%"id":"'.$eventId.'"%'])->orderByDesc('id')->limit(12)
                ->get(['id', 'action', 'occurred_at'])->map(fn (object $a): array => ['id' => (int) $a->id, 'action' => $a->action, 'at' => $a->occurred_at])->all();
        }

        return [
            'event' => ['id' => $event->id, 'name' => $event->name, 'site' => $event->site, 'kind' => $event->kind, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
                'status' => $event->status, 'is_permanent' => (bool) $event->is_permanent, 'closed' => $closed, 'anonymized' => $event->anonymized_at !== null],
            'counts' => ['participants' => (int) $counts->total, 'actifs' => (int) $counts->actifs, 'presents' => (int) $counts->presents, 'clotures' => (int) $counts->clotures,
                'groupes' => count($groups), 'vehicules' => $vehicles, 'badges_attribues' => (int) $badges->attribues, 'badges_restitues' => (int) $badges->restitues,
                'habilitations' => array_sum(array_column($zones, 'habilitations'))],
            'categories' => $categories, 'groups' => $groups, 'zones' => $zones, 'journal' => $journal,
            // Choix de l'inscription : seulement pour une activité ordinaire encore ouverte (le service d'inscription refuse le reste).
            'enrol' => $closed || $event->is_permanent ? null : [
                'categories' => $categories,
                'hosts' => DB::table('hosts')->where('event_id', $eventId)->orderBy('name')->get(['id', 'name'])->map(fn (object $h): array => ['id' => $h->id, 'name' => $h->name])->all(),
            ],
        ];
    }

    /**
     * Participants d'une activité (participations ordinaires), recherche par nom ou organisation.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public function participants(string $eventId, ?string $query, int $page): array
    {
        $now = $this->now();
        $q = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->leftJoin('groups as g', 'g.id', '=', 'v.group_id')
            ->where('v.event_id', $eventId)->whereNull('v.holder_id')->whereNull('v.withdrawn_at');
        $term = trim((string) $query);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(v.full_name) LIKE ?', [$like])->orWhereRaw('lower(coalesce(v.organisation, \'\')) LIKE ?', [$like]));
        }
        $paginated = $q->orderByRaw('v.full_name COLLATE "C" ASC')->orderBy('v.id')->select(['v.id', 'v.person_id', 'v.full_name', 'v.organisation', 'v.status', 'v.closed_at', 'c.name as category', 'g.name as group_name',
            DB::raw('(SELECT count(*) FROM participation_entitlements pe WHERE pe.visitor_id = v.id AND pe.revoked_at IS NULL AND pe.suspended_at IS NULL AND pe.valid_from <= \''.$now.'\' AND pe.valid_until > \''.$now.'\') AS habilitations'),
            DB::raw('(SELECT b.code FROM badge_assignments a JOIN badges b ON b.id = a.badge_id WHERE a.visitor_id = v.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL LIMIT 1) AS badge'),
        ])->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'data' => collect($paginated->items())->map(fn (object $p): array => ['id' => $p->id, 'person_id' => $p->person_id, 'full_name' => $p->full_name, 'organisation' => $p->organisation,
                'category' => $p->category, 'group' => $p->group_name, 'status' => $p->closed_at !== null ? 'cloturee' : ($p->status ?? 'attendu'), 'habilitations' => (int) $p->habilitations, 'badge' => $p->badge,
                'can_close' => $p->closed_at === null && $p->person_id !== null])->all(),
            'meta' => ['current_page' => $paginated->currentPage(), 'last_page' => $paginated->lastPage(), 'total' => $paginated->total()],
        ];
    }
}
