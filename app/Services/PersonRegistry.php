<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Registre unifié des personnes (V1, lot P1) : LECTURE SEULE.
 *
 * Aucune table, aucune règle ni aucun référentiel d'identité n'est ajouté : `persons` reste la référence de l'identité ; le profil permanent
 * (`permanent_holders`), les participations (`visitors`) et les deux moteurs d'habilitations (`holder_entitlements`, `participation_entitlements`)
 * sont seulement composés pour être consultés. Les habilitations se gèrent par leurs services et écrans existants.
 *
 * Distinctions maintenues (jamais fondues en une « catégorie » unique) :
 *  - statut institutionnel : catégorie institutionnelle du profil permanent, s'il existe ;
 *  - catégories de participation : propres à chaque activité, présentées dans le détail de chaque participation ;
 *  - habilitations : leur origine (permanente ou activité), leur zone, leur période et leur état.
 */
final class PersonRegistry
{
    public const PER_PAGE = 25;

    public const EXPIRY_DAYS = 30;

    private const CLOSED_EVENT = "(%s.status IS NULL OR %s.status NOT IN ('clos', 'archive')) AND %s.anonymized_at IS NULL";

    public function __construct(private readonly Persons $persons) {}

    private function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s+00');
    }

    private function eventOpen(string $alias): string
    {
        return sprintf(self::CLOSED_EVENT, $alias, $alias, $alias);
    }

    /** Habilitation de participation EFFECTIVE : ni retirée, ni suspendue, dans sa période, participation ouverte, activité ouverte. */
    private function participationActive(): string
    {
        return 'FROM participation_entitlements pe JOIN visitors v ON v.id = pe.visitor_id JOIN events e ON e.id = pe.event_id '
            .'WHERE pe.revoked_at IS NULL AND pe.suspended_at IS NULL AND pe.valid_from <= ? AND pe.valid_until > ? '
            .'AND v.closed_at IS NULL AND v.withdrawn_at IS NULL AND '.$this->eventOpen('e');
    }

    /** Habilitation permanente EFFECTIVE : non retirée, dans sa période, profil actif, activité ouverte. */
    private function holderActive(): string
    {
        return 'FROM holder_entitlements he JOIN permanent_holders hh ON hh.id = he.holder_id JOIN events e2 ON e2.id = he.event_id '
            ."WHERE he.revoked_at IS NULL AND he.valid_from <= ? AND he.valid_until > ? AND hh.status = 'actif' AND ".$this->eventOpen('e2');
    }

    /**
     * @param  array{q?: ?string, statut?: ?string, institutionnel?: ?string, habilitations?: ?string}  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $now = $this->now();
        $active = '(SELECT count(*) '.$this->participationActive().' AND v.person_id = p.id) + (SELECT count(*) '.$this->holderActive().' AND hh.person_id = p.id)';
        $bindings = [$now, $now, $now, $now];

        $q = DB::table('persons as p')
            ->leftJoin('permanent_holders as h', 'h.person_id', '=', 'p.id')
            ->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')
            ->select('p.id', 'p.full_name', 'p.organisation', 'p.status', 'h.id as holder_id', 'h.identifier', 'h.status as holder_status',
                'h.institutional_category', 'c.label as category_label', 'c.color as category_color')
            ->selectRaw('(h.photo_file IS NOT NULL) AS has_photo')
            ->selectRaw('(SELECT count(*) FROM visitors v WHERE v.person_id = p.id AND v.holder_id IS NULL AND v.closed_at IS NULL AND v.withdrawn_at IS NULL) AS participations_actives')
            ->selectRaw('(SELECT count(*) FROM visitors v WHERE v.person_id = p.id AND v.holder_id IS NULL) AS participations_total')
            ->selectRaw($active.' AS habilitations_actives', $bindings);

        $tokens = array_values(array_filter(preg_split('/\s+/u', trim((string) ($filters['q'] ?? ''))) ?: [], fn (string $t): bool => $t !== ''));
        foreach (array_slice($tokens, 0, 5) as $token) {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
            $q->whereRaw("(public.ikoma_normalize(p.full_name) LIKE '%' || public.ikoma_normalize(?) || '%'"
                ." OR public.ikoma_normalize(coalesce(p.organisation, '')) LIKE '%' || public.ikoma_normalize(?) || '%'"
                ." OR public.ikoma_normalize(coalesce(h.identifier, '')) LIKE '%' || public.ikoma_normalize(?) || '%')", [$like, $like, $like]);
        }
        if (in_array($filters['statut'] ?? null, ['actif', 'archive'], true)) {
            $q->where('p.status', $filters['statut']);
        }
        $institutional = $filters['institutionnel'] ?? null;
        if ($institutional === 'aucun') {
            $q->whereNull('h.institutional_category');
        } elseif (is_string($institutional) && $institutional !== '') {
            $q->where('h.institutional_category', $institutional);
        }
        if (in_array($filters['habilitations'] ?? null, ['avec', 'sans'], true)) {
            $q->whereRaw('('.$active.') '.($filters['habilitations'] === 'avec' ? '> 0' : '= 0'), $bindings);
        }

        return $q->orderByRaw('public.ikoma_normalize(p.full_name)')->orderBy('p.id')->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Indicateurs réels : aucun chiffre illustratif.
     *
     * @return array{personnes: int, habilitations_actives: int, a_echeance: int, retirees_ou_expirees: int, jours_echeance: int}
     */
    public function kpis(): array
    {
        $now = $this->now();
        $soon = now()->utc()->addDays(self::EXPIRY_DAYS)->format('Y-m-d H:i:s+00');
        $count = fn (string $sql, array $bindings): int => (int) DB::selectOne($sql, $bindings)->n;

        $active = $count('SELECT (SELECT count(*) '.$this->participationActive().') + (SELECT count(*) '.$this->holderActive().') AS n', [$now, $now, $now, $now]);
        $expiring = $count('SELECT (SELECT count(*) '.$this->participationActive().' AND pe.valid_until <= ?) + (SELECT count(*) '.$this->holderActive().' AND he.valid_until <= ?) AS n',
            [$now, $now, $soon, $now, $now, $soon]);
        $ended = $count('SELECT (SELECT count(*) FROM participation_entitlements pe WHERE pe.revoked_at IS NOT NULL OR pe.valid_until <= ?)'
            .' + (SELECT count(*) FROM holder_entitlements he WHERE he.revoked_at IS NOT NULL OR he.valid_until <= ?) AS n', [$now, $now]);

        return ['personnes' => DB::table('persons')->count(), 'habilitations_actives' => $active, 'a_echeance' => $expiring, 'retirees_ou_expirees' => $ended, 'jours_echeance' => self::EXPIRY_DAYS];
    }

    /**
     * Statuts institutionnels disponibles pour le filtre (catégories réellement initialisées).
     *
     * @return array<int, array{code: string, label: string}>
     */
    public function institutionalOptions(): array
    {
        return DB::table('institutional_categories')->orderBy('code')->get(['code', 'label'])->map(fn ($c): array => ['code' => $c->code, 'label' => $c->label])->all();
    }

    /** État d'une habilitation, d'après ses seules données. */
    private function state(object $row, ?string $participationClosed = null, ?string $participationWithdrawn = null, bool $holderClosed = false): string
    {
        $now = CarbonImmutable::now();
        if ($row->revoked_at !== null) {
            return 'retiree';
        }
        if (property_exists($row, 'suspended_at') && $row->suspended_at !== null) {
            return 'suspendue';
        }
        if ($holderClosed) {
            return 'profil_clos';
        }
        if ($participationClosed !== null || $participationWithdrawn !== null) {
            return 'participation_close';
        }
        if (in_array($row->event_status, ['clos', 'archive'], true) || $row->anonymized_at !== null) {
            return 'activite_close';
        }
        if (CarbonImmutable::parse($row->valid_until)->lessThanOrEqualTo($now)) {
            return 'expiree';
        }
        if (CarbonImmutable::parse($row->valid_from)->greaterThan($now)) {
            return 'a_venir';
        }

        return 'active';
    }

    /**
     * Opérations que le moteur d'origine PERMET dans l'état courant (la permission, elle, reste contrôlée côté serveur par les routes et services existants).
     * Habilitation permanente : retrait seulement (ni suspension, ni reprise, ni modification). Habilitation d'activité : suspension, reprise, retrait.
     *
     * @return list<string>
     */
    private function actions(string $origin, string $state): array
    {
        if ($origin === 'permanente') {
            return in_array($state, ['active', 'a_venir'], true) ? ['retirer'] : [];
        }

        return match ($state) {
            'active', 'a_venir' => ['suspendre', 'retirer'],
            'suspendue' => ['reprendre', 'retirer'],
            default => [],
        };
    }

    /**
     * Zones des activités indiquées, groupées par activité (boucle simple : types explicites pour l'analyse statique).
     *
     * @param  list<mixed>  $eventIds
     * @return array<string, list<array{id: mixed, name: mixed}>>
     */
    private function zonesByEvent(array $eventIds): array
    {
        $byEvent = [];
        foreach (DB::table('zones')->whereIn('event_id', $eventIds)->orderBy('name')->get(['id', 'event_id', 'name']) as $z) {
            $byEvent[(string) $z->event_id][] = ['id' => $z->id, 'name' => $z->name];
        }

        return $byEvent;
    }

    /**
     * Choix proposés aux formulaires d'attribution : activités ouvertes et leurs zones (permanent, si le profil est actif) ; participations ouvertes et zones de leur activité.
     *
     * @param  list<object>  $participations
     * @return array{permanent: array<string, mixed>|null, activites: list<array<string, mixed>>, max_exceptional_hours: int}
     */
    private function grantOptions(?object $holder, array $participations): array
    {
        $open = fn (Builder $q): Builder => $q->where('is_permanent', false)->whereNull('anonymized_at')->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['clos', 'archive']));
        $permanent = null;
        if ($holder !== null && $holder->status === 'actif') {
            $events = $open(DB::table('events'))->orderByDesc('starts_at')->limit(50)->get(['id', 'name', 'starts_at', 'ends_at']);
            $zones = $this->zonesByEvent($events->pluck('id')->all());
            $permanent = ['holder_id' => $holder->id, 'events' => $events->map(fn (object $e): array => ['id' => $e->id, 'name' => $e->name, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at,
                'zones' => $zones[$e->id] ?? []])->all()];
        }

        $eligible = array_values(array_filter($participations, fn (object $p): bool => $p->holder_id === null && $p->closed_at === null && $p->withdrawn_at === null && $p->status !== 'refuse'
            && ! in_array($p->event_status, ['clos', 'archive'], true)));
        $zones = $this->zonesByEvent(array_values(array_unique(array_map(fn (object $p): string => $p->event_id, $eligible))));
        $activities = array_map(fn (object $p): array => ['participation_id' => $p->id, 'event_id' => $p->event_id, 'event' => $p->event_name, 'valid_from' => $p->valid_from, 'valid_until' => $p->valid_until,
            'zones' => $zones[$p->event_id] ?? []], $eligible);

        return ['permanent' => $permanent, 'activites' => $activities, 'max_exceptional_hours' => ParticipationEntitlements::maxExceptionalHours()];
    }

    /**
     * Fiche d'une personne : identité, profil permanent, participations, habilitations par origine, badges (consultation), historique dérivé.
     *
     * @return array<string, mixed>|null
     */
    public function profile(string $personId): ?array
    {
        if (! DB::table('persons')->where('id', $personId)->exists()) {
            return null;
        }
        $history = $this->persons->history($personId);
        $person = DB::table('persons')->where('id', $personId)->first(['id', 'first_name', 'last_name', 'full_name', 'organisation', 'status', 'created_at', 'updated_at']);
        $holder = DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')->where('h.person_id', $personId)
            ->first(['h.id', 'h.identifier', 'h.position', 'h.quality', 'h.status', 'h.institutional_category', 'c.label as category_label', 'c.color as category_color',
                'h.engagement_starts_on', 'h.engagement_ends_on', 'h.closed_at', 'h.created_at']);
        $hasPhoto = $holder !== null && DB::table('permanent_holders')->where('id', $holder->id)->whereNotNull('photo_file')->exists();

        $zoneCols = ['z.id as zone_id', 'z.name as zone_name', 'e.id as event_id', 'e.name as event_name', 'e.status as event_status', 'e.anonymized_at'];
        $activityRights = DB::table('participation_entitlements as pe')->join('visitors as v', 'v.id', '=', 'pe.visitor_id')->join('events as e', 'e.id', '=', 'pe.event_id')->join('zones as z', 'z.id', '=', 'pe.zone_id')
            ->where('v.person_id', $personId)->orderByDesc('pe.valid_until')
            ->get(['pe.id', 'pe.kind', 'pe.reason', 'pe.valid_from', 'pe.valid_until', 'pe.suspended_at', 'pe.revoked_at', 'pe.created_at', 'v.closed_at as participation_closed_at', 'v.withdrawn_at as participation_withdrawn_at', ...$zoneCols])
            ->map(fn (object $r): array => ['id' => $r->id, 'origin' => 'activite', 'zone_id' => $r->zone_id, 'zone' => $r->zone_name, 'event_id' => $r->event_id, 'event' => $r->event_name,
                'kind' => $r->kind, 'reason' => $r->kind === 'exceptionnelle' ? $r->reason : null, 'valid_from' => $r->valid_from, 'valid_until' => $r->valid_until, 'created_at' => $r->created_at,
                'suspended_at' => $r->suspended_at, 'revoked_at' => $r->revoked_at, 'state' => $state = $this->state($r, $r->participation_closed_at, $r->participation_withdrawn_at),
                'actions' => $this->actions('activite', $state)])->all();
        $permanentRights = DB::table('holder_entitlements as he')->join('permanent_holders as hh', 'hh.id', '=', 'he.holder_id')->join('events as e', 'e.id', '=', 'he.event_id')->join('zones as z', 'z.id', '=', 'he.zone_id')
            ->where('hh.person_id', $personId)->orderByDesc('he.valid_until')
            ->get(['he.id', 'he.valid_from', 'he.valid_until', 'he.revoked_at', 'he.created_at', 'hh.status as holder_status', ...$zoneCols])
            ->map(fn (object $r): array => ['id' => $r->id, 'origin' => 'permanente', 'zone_id' => $r->zone_id, 'zone' => $r->zone_name, 'event_id' => $r->event_id, 'event' => $r->event_name,
                'kind' => null, 'reason' => null, 'valid_from' => $r->valid_from, 'valid_until' => $r->valid_until, 'created_at' => $r->created_at,
                'suspended_at' => null, 'revoked_at' => $r->revoked_at, 'state' => $state = $this->state($r, null, null, $r->holder_status !== 'actif'),
                'actions' => $this->actions('permanente', $state)])->all();

        // Synthèse des accès : uniquement ce que les habilitations effectives ouvrent réellement.
        $effective = array_values(array_filter([...$permanentRights, ...$activityRights], fn (array $r): bool => $r['state'] === 'active'));
        $zoneIds = array_values(array_unique(array_column($effective, 'zone_id')));
        $posts = $zoneIds === [] ? [] : DB::table('checkpoint_zones as cz')->join('checkpoints as c', 'c.id', '=', 'cz.checkpoint_id')->whereIn('cz.zone_id', $zoneIds)->where('c.active', true)
            ->distinct()->orderBy('c.code')->get(['c.id', 'c.code', 'c.name'])->map(fn ($c): array => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->all();
        $zones = array_values(array_unique(array_map(fn (array $r): string => $r['zone'].' · '.$r['event'], $effective)));
        sort($zones);
        $next = $effective === [] ? null : array_reduce($effective, fn (?string $min, array $r): string => $min === null || $r['valid_until'] < $min ? $r['valid_until'] : $min);

        return [
            'person' => $person,
            'holder' => $holder === null ? null : ['id' => $holder->id, 'identifier' => $holder->identifier, 'position' => $holder->position, 'quality' => $holder->quality, 'status' => $holder->status,
                'institutional_category' => $holder->institutional_category, 'category_label' => $holder->category_label, 'category_color' => $holder->category_color,
                'engagement_starts_on' => $holder->engagement_starts_on, 'engagement_ends_on' => $holder->engagement_ends_on, 'has_photo' => $hasPhoto],
            'participations' => $history['participations'],
            'assignments' => $history['assignments'],
            'entitlements' => ['permanentes' => $permanentRights, 'activites' => $activityRights],
            'grant' => $this->grantOptions($holder, $history['participations']),
            'access' => ['zones' => $zones, 'posts' => $posts, 'next_expiry' => $next, 'effective' => count($effective)],
            'timeline' => $this->timeline($person, $holder, $history, [...$permanentRights, ...$activityRights]),
        ];
    }

    /**
     * Historique dérivé des dates réellement enregistrées (aucune donnée ajoutée, aucun motif ni commentaire libre).
     *
     * @param  array<string, mixed>  $history
     * @param  list<array<string, mixed>>  $rights
     * @return list<array{at: string, label: string}>
     */
    private function timeline(object $person, ?object $holder, array $history, array $rights): array
    {
        $rows = [['at' => (string) $person->created_at, 'label' => 'Identité enregistrée']];
        if ($holder !== null) {
            $rows[] = ['at' => (string) $holder->created_at, 'label' => 'Profil permanent enregistré ('.$holder->identifier.')'];
            if ($holder->closed_at !== null) {
                $rows[] = ['at' => (string) $holder->closed_at, 'label' => 'Profil permanent clos'];
            }
        }
        foreach ($history['participations'] as $p) {
            if ($p->holder_id !== null) {
                continue;
            }
            $rows[] = ['at' => (string) $p->created_at, 'label' => 'Inscrite à « '.$p->event_name.' »'];
            if ($p->closed_at !== null) {
                $rows[] = ['at' => (string) $p->closed_at, 'label' => 'Participation clôturée : « '.$p->event_name.' »'];
            }
            if ($p->withdrawn_at !== null) {
                $rows[] = ['at' => (string) $p->withdrawn_at, 'label' => 'Participation retirée : « '.$p->event_name.' »'];
            }
        }
        foreach ($rights as $r) {
            $what = $r['zone'].' ('.($r['origin'] === 'permanente' ? 'permanente' : $r['event']).')';
            $rows[] = ['at' => (string) $r['created_at'], 'label' => 'Habilitation accordée : '.$what];
            if ($r['suspended_at'] !== null) {
                $rows[] = ['at' => (string) $r['suspended_at'], 'label' => 'Habilitation suspendue : '.$what];
            }
            if ($r['revoked_at'] !== null) {
                $rows[] = ['at' => (string) $r['revoked_at'], 'label' => 'Habilitation retirée : '.$what];
            }
        }
        foreach ($history['assignments'] as $a) {
            $rows[] = ['at' => (string) $a->assigned_at, 'label' => 'Badge '.$a->badge_code.' attribué'];
            if ($a->returned_at !== null) {
                $rows[] = ['at' => (string) $a->returned_at, 'label' => 'Badge '.$a->badge_code.' restitué'];
            }
            if ($a->cancelled_at !== null) {
                $rows[] = ['at' => (string) $a->cancelled_at, 'label' => 'Attribution du badge '.$a->badge_code.' annulée'];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, 100);
    }
}
