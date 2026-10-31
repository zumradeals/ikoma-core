<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Recherche d'un visiteur dans un événement : tolérante aux accents et aux fautes, et valable sur n'importe quel mot du nom
 * (« Koné » retrouve « Marie Koné » comme « Koné Mariam »). Tous les mots saisis doivent correspondre à un mot du nom.
 * Classement : qualité de la correspondance (par dixième), puis VIP (circuit prioritaire) en premier à qualité égale, puis score fin.
 */
final class VisitorSearch
{
    private const MAX_TOKENS = 6;

    /** Seuil de similarité d'un mot saisi avec un mot du nom (pg_trgm.word_similarity_threshold). */
    private const FUZZY_THRESHOLD = '0.4';

    /** @return list<array{id:string,full_name:string,category:array{id:string,name:string,color:string,priority_circuit:bool}}> */
    public function search(string $eventId, string $query): array
    {
        return array_map(fn (array $row) => ['id' => $row['id'], 'full_name' => $row['full_name'], 'category' => $row['category']], $this->searchDetailed($eventId, $query));
    }

    /**
     * Les dix premières personnes de l'événement du poste, même sans recherche.
     * Ne jamais élargir à d'autres événements ni exposer les personnes retirées.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(string $eventId): array
    {
        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Événement invalide.');
        }

        $rows = DB::table('visitors as v')
            ->join('categories as c', 'c.id', '=', 'v.category_id')
            ->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')
            ->where('v.event_id', $eventId)
            ->whereNull('v.withdrawn_at')
            ->orderByRaw('v.full_name COLLATE "C" ASC')->orderBy('v.id')
            ->limit(10)
            ->get(['v.id', 'v.full_name', 'v.organisation', 'h.name as host_name',
                'c.id as category_id', 'c.name as category_name', 'c.color', 'c.priority_circuit']);

        return $rows->map(function ($row) use ($eventId): array {
            $homonyms = DB::table('visitors')
                ->where('event_id', $eventId)->whereNull('withdrawn_at')
                ->whereRaw('public.ikoma_normalize(full_name) = public.ikoma_normalize(?)', [$row->full_name])
                ->count();

            return ['id' => $row->id, 'full_name' => $row->full_name, 'organisation' => $row->organisation,
                'host' => $row->host_name, 'homonyms' => $homonyms,
                'category' => ['id' => $row->category_id, 'name' => $row->category_name,
                    'color' => $row->color, 'priority_circuit' => (bool) $row->priority_circuit]];
        })->all();
    }

    /**
     * Même recherche avec les éléments utiles au second critère en cas d'homonyme (organisation, personne reçue).
     *
     * @return list<array{id:string,full_name:string,organisation:?string,host:?string,homonyms:int,category:array{id:string,name:string,color:string,priority_circuit:bool}}>
     */
    public function searchDetailed(string $eventId, string $query): array
    {
        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Événement invalide.');
        }
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 160) {
            return [];
        }
        $normalized = DB::selectOne('SELECT public.ikoma_normalize(?) AS value', [$query])->value;
        preg_match_all('/[\p{L}\p{N}]+/u', (string) $normalized, $matches);
        $tokens = array_slice(array_values(array_unique($matches[0])), 0, self::MAX_TOKENS);
        if ($tokens === [] || mb_strlen(implode('', $tokens)) < 2) {
            return [];
        }
        $name = 'public.ikoma_normalize(v.full_name)';
        $where = [];
        $score = [];
        $bindings = [];
        $scoreBindings = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= 3) {
                $where[] = "($name LIKE ? OR ? <% $name)";
                $bindings[] = '%'.$token.'%';
                $bindings[] = $token;
            } else {
                $where[] = "($name LIKE ? OR $name LIKE ?)";
                $bindings[] = $token.'%';
                $bindings[] = '% '.$token.'%';
            }
            $score[] = "(CASE WHEN $name LIKE ? THEN 1.0 WHEN $name LIKE ? THEN 0.9 WHEN $name LIKE ? THEN 0.7 ELSE word_similarity(?, $name) END)";
            array_push($scoreBindings, $token.'%', '% '.$token.'%', '%'.$token.'%', $token);
        }
        $sql = 'SELECT v.id, v.full_name, v.organisation, h.name AS host_name, c.id AS category_id, c.name AS category_name, c.color, c.priority_circuit, '
            ."(SELECT count(*) FROM visitors o WHERE o.event_id = v.event_id AND o.withdrawn_at IS NULL AND public.ikoma_normalize(o.full_name) = $name) AS homonyms "
            .'FROM visitors v JOIN categories c ON c.id = v.category_id LEFT JOIN hosts h ON h.id = v.host_id '
            .'WHERE v.event_id = ? AND v.withdrawn_at IS NULL AND '.implode(' AND ', $where)
            .' ORDER BY round((('.implode(' + ', $score).') / ?)::numeric, 1) DESC, c.priority_circuit DESC, ('.implode(' + ', $score).') DESC, v.full_name COLLATE "C", v.id LIMIT 10';
        // Le seuil ne vaut que pour cette transaction ; paramètres toujours liés.
        $rows = DB::transaction(function () use ($sql, $eventId, $bindings, $scoreBindings, $tokens) {
            DB::statement("SELECT set_config('pg_trgm.word_similarity_threshold', ?, true)", [self::FUZZY_THRESHOLD]);

            return DB::select($sql, [$eventId, ...$bindings, ...$scoreBindings, (float) count($tokens), ...$scoreBindings]);
        });

        return array_map(fn ($row) => ['id' => $row->id, 'full_name' => $row->full_name, 'organisation' => $row->organisation, 'host' => $row->host_name, 'homonyms' => (int) $row->homonyms, 'category' => ['id' => $row->category_id, 'name' => $row->category_name, 'color' => $row->color, 'priority_circuit' => (bool) $row->priority_circuit]], $rows);
    }
}
