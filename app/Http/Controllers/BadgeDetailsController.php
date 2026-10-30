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

        return Inertia::render('Admin/BadgeDetails', ['badge' => $row, 'history' => DB::table('badge_assignments')->where('badge_id', $badge)->orderByDesc('assigned_at')->get(['id', 'visitor_id', 'actor_id', 'assigned_at', 'returned_at', 'cancelled_at'])]);
    }
}
