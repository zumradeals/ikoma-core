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
        $query = DB::table('badges as b')->join('badge_batches as batch', 'batch.id', '=', 'b.badge_batch_id')->leftJoin('devices as device', 'device.id', '=', 'b.reserved_device_id');
        if (! empty($filters['status'])) {
            $query->where('b.status', $filters['status']);
        }
        if (! empty($filters['batch'])) {
            $query->where('b.badge_batch_id', $filters['batch']);
        }
        if (($filters['reserved'] ?? '') === 'oui') {
            $query->whereNotNull('b.reserved_device_id');
        }
        if (($filters['reserved'] ?? '') === 'non') {
            $query->whereNull('b.reserved_device_id');
        }

        return Inertia::render('Admin/Badges', ['badges' => $query->orderBy('b.code')->paginate(30, ['b.id', 'b.code', 'b.status', 'batch.name as batch_name', 'device.station as reserved_station'])->withQueryString(), 'batches' => DB::table('badge_batches')->orderBy('name')->get(['id', 'name']), 'statuses' => array_column(BadgeStatus::cases(), 'value'), 'filters' => $filters]);
    }
}
