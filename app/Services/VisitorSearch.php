<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class VisitorSearch
{
    /** @return list<array{id:string,full_name:string,category:array{id:string,name:string,color:string,priority_circuit:bool}}> */
    public function search(string $eventId, string $query): array
    {
        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('Événement invalide.');
        }
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 160) {
            return [];
        }
        $needle = DB::selectOne('SELECT public.ikoma_normalize(?) AS value', [$query])->value;

        // Paramètres liés, événement explicite ; aucune route publique de recherche dans L2.
        return DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')
            ->where('v.event_id', $eventId)
            ->where(fn (Builder $q) => $q->whereRaw('public.ikoma_normalize(v.full_name) % ?', [$needle])->orWhereRaw('public.ikoma_normalize(v.full_name) LIKE ?', [addcslashes($needle, '\\%_').'%']))
            ->select(['v.id', 'v.full_name', 'c.id as category_id', 'c.name as category_name', 'c.color', 'c.priority_circuit'])
            ->orderByRaw('similarity(public.ikoma_normalize(v.full_name), ?) DESC', [$needle])->orderBy('v.full_name')->orderBy('v.id')->limit(10)->get()
            ->map(fn ($row) => ['id' => $row->id, 'full_name' => $row->full_name, 'category' => ['id' => $row->category_id, 'name' => $row->category_name, 'color' => $row->color, 'priority_circuit' => (bool) $row->priority_circuit]])->all();
    }
}
