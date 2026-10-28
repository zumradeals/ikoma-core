<?php

namespace App\Http\Controllers;

use App\Enums\BadgeStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BadgesController
{
    public function __invoke(Request $r): Response
    {
        Gate::authorize('badges.gerer');
        $filters = $r->validate(['status' => ['nullable', Rule::enum(BadgeStatus::class)], 'batch' => 'bail|nullable|uuid|exists:badge_batches,id', 'reserved' => ['nullable', Rule::in(['oui', 'non'])]]);
        $query = DB::table('badges as b')->join('badge_batches as batch', 'batch.id', '=', 'b.badge_batch_id')->leftJoin('devices as device', 'device.id', '=', 'b.reserved_device_id')->leftJoin('groups as grp', 'grp.id', '=', 'b.reserved_group_id')->leftJoin('categories as cat', 'cat.id', '=', 'b.category_id');
        if (! empty($filters['status'])) {
            $query->where('b.status', $filters['status']);
        }
        if (! empty($filters['batch'])) {
            $query->where('b.badge_batch_id', $filters['batch']);
        }
        if (($filters['reserved'] ?? '') === 'oui') {
            $query->where(fn ($q) => $q->whereNotNull('b.reserved_device_id')->orWhereNotNull('b.reserved_group_id'));
        }
        if (($filters['reserved'] ?? '') === 'non') {
            $query->whereNull('b.reserved_device_id')->whereNull('b.reserved_group_id');
        }
        $number = "CASE WHEN code ~ '^B-[0-9]+\$' THEN substring(code from 3)::integer END";
        $batches = DB::table('badge_batches as batch')->leftJoin('categories as cat', 'cat.id', '=', 'batch.category_id')->join('events as ev', 'ev.id', '=', 'batch.event_id')
            ->selectRaw("batch.id, batch.name, batch.event_id, ev.name as event_name, batch.quantity, batch.margin, cat.name as category_name, (SELECT min($number) FROM badges WHERE badge_batch_id = batch.id) as first_number, (SELECT max($number) FROM badges WHERE badge_batch_id = batch.id) as last_number")
            ->orderBy('batch.name')->get();
        $setting = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');

        return Inertia::render('Admin/Badges', [
            'badges' => $query->orderBy('b.code')->paginate(30, ['b.id', 'b.code', 'b.status', 'batch.name as batch_name', 'device.station as reserved_station', 'grp.name as reserved_group', 'cat.name as category_name', 'cat.color as category_color', 'cat.priority_circuit as category_priority'])->withQueryString(),
            'batches' => $batches,
            'statuses' => array_column(BadgeStatus::cases(), 'value'),
            'filters' => $filters,
            'events' => DB::table('events')->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'event_id', 'name', 'color']),
            'devices' => DB::table('devices')->orderBy('name')->get(['id', 'event_id', 'name', 'station']),
            'groups' => DB::table('groups')->orderBy('name')->get(['id', 'event_id', 'name']),
            'settings' => ['margin' => (int) $setting('badge_marge_pourcent'), 'reusable' => $setting('badge_reutilisable') === 'oui'],
        ]);
    }
}
