<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Lectures du tableau de bord du superviseur : compteurs, alertes, présents, évacuation, groupes. Aucune écriture. */
final class Pilotage
{
    public const PER_PAGE = 50;

    public function __construct(private readonly Presence $presence) {}

    /** Événement affiché : celui demandé, sinon celui qui est en cours, sinon le plus récent. */
    public function event(?string $requested): ?object
    {
        $events = DB::table('events')->orderByDesc('starts_at')->get(['id', 'name', 'starts_at', 'ends_at']);
        if ($requested !== null && ($found = $events->firstWhere('id', $requested)) !== null) {
            return $found;
        }
        $now = now()->toDateTimeString();

        return $events->first(fn ($e) => $e->starts_at <= $now && $e->ends_at >= $now) ?? $events->first();
    }

    /**
     * Attendus : personnes attendues, refusées exclues (listées et non listées enregistrées). Arrivés : personnes dont une entrée non corrigée existe,
     * même sorties depuis. Présents : compteur exact du lot L7. Refusés : accès refusé et non autorisé depuis.
     *
     * @return array{attendus:int,arrives:int,presents:int,refuses:int,as_of:string}
     */
    public function counters(string $eventId): array
    {
        $row = DB::selectOne(
            "SELECT
                (SELECT count(*) FROM visitors WHERE event_id = ? AND coalesce(status, 'attendu') <> 'refuse') AS attendus,
                (SELECT count(DISTINCT e.visitor_id) FROM access_events e WHERE e.event_id = ? AND e.type = 'entree' AND NOT EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id)) AS arrives,
                (SELECT count(*) FROM visitors WHERE event_id = ? AND status = 'refuse') AS refuses",
            [$eventId, $eventId, $eventId],
        );

        return ['attendus' => (int) $row->attendus, 'arrives' => (int) $row->arrives, 'presents' => $this->presence->count($eventId), 'refuses' => (int) $row->refuses, 'as_of' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    /** @return list<array<string, mixed>> */
    public function alerts(string $eventId, string $state, int $limit = 100): array
    {
        $q = DB::table('alerts')->where('event_id', $eventId);
        $state === 'prises' ? $q->where(fn ($w) => $w->whereNotNull('acknowledged_at')->orWhereNotNull('resolved_at')) : $q->whereNull('acknowledged_at')->whereNull('resolved_at');

        return $q->orderByRaw("CASE severity WHEN 'critique' THEN 0 WHEN 'haute' THEN 1 ELSE 2 END")->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'type', 'severity', 'subject_type', 'subject_id', 'message', 'created_at', 'acknowledged_at', 'resolved_at'])
            ->map(fn ($a) => (array) $a + ['link' => $this->link($a->subject_type, $a->subject_id, $a->type)])->all();
    }

    public function openAlertCount(string $eventId): int
    {
        return DB::table('alerts')->where('event_id', $eventId)->whereNull('acknowledged_at')->whereNull('resolved_at')->count();
    }

    private function link(?string $type, ?string $id, string $alertType): ?string
    {
        return match (true) {
            $type === 'visiteur' && $alertType === 'refus' => '/pilotage/litiges',
            $type === 'visiteur' => '/pilotage/visiteurs/'.$id,
            $type === 'conflit' => '/administration/postes',
            $type === 'groupe' => '/pilotage/groupes',
            default => null,
        };
    }

    /**
     * Personnes présentes : dernier événement non corrigé = une entrée. Zone = dernier point de contrôle de type zone depuis l'entrée, sinon « Accueil ».
     *
     * @param  array{q?:?string,category?:?string,zone?:?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function presentRows(string $eventId, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$sql, $bindings] = $this->presentSql($eventId, $filters);
        $sql .= ' ORDER BY p.entered_at DESC, p.full_name, p.id'.($limit !== null ? ' LIMIT '.(int) $limit.' OFFSET '.(int) $offset : '');
        // Des statistiques de table faussées (tables vidées puis remplies, comme dans les tests) font choisir des boucles imbriquées : le temps explose
        // avec le nombre de présents. Des jointures par hachage restent rapides dans tous les cas ; le réglage ne vaut que pour cette requête.
        $rows = DB::transaction(function () use ($sql, $bindings): array {
            DB::statement("SELECT set_config('enable_nestloop', 'off', true)");

            return DB::select($sql, $bindings);
        });

        return array_map(fn ($r) => [
            'id' => $r->id, 'full_name' => $r->full_name, 'organisation' => $r->organisation, 'group' => $r->group_name, 'zone' => $r->zone, 'entered_at' => $r->entered_at,
            'category' => ['id' => $r->category_id, 'name' => $r->category_name, 'color' => $r->color, 'priority_circuit' => (bool) $r->priority_circuit],
        ], $rows);
    }

    /** @param array{q?:?string,category?:?string,zone?:?string}  $filters */
    public function presentCount(string $eventId, array $filters = []): int
    {
        [$sql, $bindings] = $this->presentSql($eventId, $filters);

        return (int) DB::selectOne('SELECT count(*) AS n FROM ('.$sql.') x', $bindings)->n;
    }

    /**
     * @param  array{q?:?string,category?:?string,zone?:?string}  $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function presentSql(string $eventId, array $filters): array
    {
        $sql = "SELECT * FROM (
            SELECT v.id, v.full_name, v.organisation, c.id AS category_id, c.name AS category_name, c.color, c.priority_circuit, g.name AS group_name, l.occurred_at AS entered_at,
                coalesce(z.checkpoint, 'Accueil') AS zone
            FROM (
                SELECT DISTINCT ON (e.visitor_id) e.visitor_id, e.type, e.occurred_at FROM access_events e
                WHERE e.event_id = ? AND e.type IN ('entree', 'sortie') AND e.visitor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM access_events x WHERE x.corrige_id = e.id)
                ORDER BY e.visitor_id, e.occurred_at DESC, e.created_at DESC, e.id DESC
            ) l JOIN visitors v ON v.id = l.visitor_id JOIN categories c ON c.id = v.category_id LEFT JOIN groups g ON g.id = v.group_id
            LEFT JOIN (
                SELECT DISTINCT ON (a.visitor_id) a.visitor_id, a.checkpoint, a.occurred_at FROM access_events a
                WHERE a.event_id = ? AND a.type = 'controle' AND a.details->>'point_type' = 'zone' AND a.visitor_id IS NOT NULL
                ORDER BY a.visitor_id, a.occurred_at DESC
            ) z ON z.visitor_id = v.id AND z.occurred_at >= l.occurred_at
            WHERE l.type = 'entree'
        ) p WHERE true";
        $bindings = [$eventId, $eventId];
        if (! empty($filters['q'])) {
            $sql .= ' AND public.ikoma_normalize(p.full_name) LIKE ?';
            $bindings[] = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower(DB::selectOne('SELECT public.ikoma_normalize(?) AS v', [trim($filters['q'])])->v)).'%';
        }
        if (! empty($filters['category'])) {
            $sql .= ' AND p.category_id = ?';
            $bindings[] = $filters['category'];
        }
        if (! empty($filters['zone'])) {
            $sql .= ' AND p.zone = ?';
            $bindings[] = $filters['zone'];
        }

        return [$sql, $bindings];
    }

    /**
     * Liste d'évacuation : présents regroupés par zone avec le total de chaque zone.
     *
     * @return array{event:array<string,mixed>,total:int,zones:list<array{name:string,count:int,people:list<array<string,mixed>>}>,as_of:string}
     */
    public function evacuation(object $event): array
    {
        $zones = [];
        foreach ($this->presentRows($event->id) as $person) {
            $zones[$person['zone']][] = $person;
        }
        ksort($zones);
        $list = [];
        foreach ($zones as $name => $people) {
            usort($people, fn ($a, $b) => strcmp($a['full_name'], $b['full_name']));
            $list[] = ['name' => (string) $name, 'count' => count($people), 'people' => $people];
        }

        return ['event' => ['id' => $event->id, 'name' => $event->name, 'ends_at' => $event->ends_at], 'total' => array_sum(array_column($list, 'count')), 'zones' => $list, 'as_of' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }

    /**
     * Groupes : structure prête pour le lot L9 (appel nominal, embarquement, arrivée) ; vide tant que rien n'est saisi.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(string $eventId): array
    {
        return array_map(fn ($g) => (array) $g, DB::select(
            'SELECT g.id, g.name, g.bus_number,
                (SELECT count(*) FROM visitors v WHERE v.group_id = g.id) AS total,
                (SELECT count(*) FROM visitors v WHERE v.group_id = g.id AND EXISTS (SELECT 1 FROM badge_assignments a WHERE a.visitor_id = v.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL)) AS badges_remis,
                (SELECT count(*) FROM group_members m WHERE m.group_id = g.id AND m.boarded_at IS NOT NULL) AS embarques,
                (SELECT count(*) FROM group_members m WHERE m.group_id = g.id AND m.arrived_at IS NOT NULL) AS arrives,
                (SELECT count(*) FROM visitors v WHERE v.group_id = g.id AND NOT EXISTS (SELECT 1 FROM group_members m WHERE m.visitor_id = v.id AND m.arrived_at IS NOT NULL)) AS absents,
                (SELECT count(*) FROM group_members m WHERE m.group_id = g.id AND m.absent_at IS NOT NULL) AS absents_depart,
                (SELECT count(*) FROM alerts al WHERE al.subject_type = \'groupe\' AND al.subject_id = g.id AND al.type IN (\'groupe_ecart\',\'groupe_incomplet\') AND al.resolved_at IS NULL) AS ecarts,
                (g.departed_at IS NOT NULL) AS parti
             FROM groups g WHERE g.event_id = ? ORDER BY g.name',
            [$eventId],
        ));
    }
}
