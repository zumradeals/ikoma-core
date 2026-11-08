<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class BadgeDetailsController
{
    public function __invoke(string $badge): Response
    {
        Gate::authorize('badges.gerer');
        $row = DB::table('badges as b')->join('badge_batches as lot', 'lot.id', '=', 'b.badge_batch_id')->leftJoin('categories as c', 'c.id', '=', 'b.category_id')->where('b.id', $badge)->first(['b.id', 'b.event_id', 'b.code', 'b.status', 'b.reserved_device_id', 'b.reserved_group_id', 'b.printed_for_visitor_id', 'lot.name as lot', 'c.name as category']);
        abort_unless($row !== null, 404);

        return Inertia::render('Admin/BadgeDetails', [
            'badge' => $row,
            // Historique complet : chaque attribution porte l'activité et la personne (peu importe l'activité actuelle du badge), puis les mises à disposition.
            'history' => DB::table('badge_assignments as a')->join('events as e', 'e.id', '=', 'a.event_id')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->join('persons as p', 'p.id', '=', 'v.person_id')
                ->where('a.badge_id', $badge)->orderByDesc('a.assigned_at')->get(['a.id', 'a.visitor_id', 'a.actor_id', 'a.assigned_at', 'a.returned_at', 'a.cancelled_at', 'e.name as event_name', 'p.id as person_id', 'p.full_name as person_name']),
            'transfers' => DB::table('badge_transfers as t')->join('events as f', 'f.id', '=', 't.from_event_id')->join('events as e', 'e.id', '=', 't.to_event_id')
                ->where('t.badge_id', $badge)->orderByDesc('t.created_at')->get(['t.id', 'f.name as from_name', 'e.name as to_name', 't.reservation_released', 't.created_at']),
        ]);
    }
}
