<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Zone;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ZonesController
{
    public function index(): Response
    {
        Gate::authorize('zones.gerer');

        return Inertia::render('Admin/Zones', ['events' => Event::orderBy('name')->get(['id', 'name']), 'zones' => Zone::query()->join('events', 'events.id', '=', 'zones.event_id')->orderBy('events.name')->orderBy('zones.name')->get(['zones.id', 'zones.event_id', 'zones.name', 'events.name as event_name'])]);
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('zones.gerer');
        $r->validate(['event_id' => 'bail|required|uuid|exists:events,id']);
        $data = $r->validate(['event_id' => 'bail|required|uuid|exists:events,id', 'name' => ['required', 'string', 'max:120', Rule::unique('zones')->where('event_id', $r->input('event_id'))]]);
        DB::transaction(function () use ($data): void {
            $zone = Zone::create($data);
            app(Audit::class)->append('zone.creee', auth()->id(), ['id' => $zone->id, 'event_id' => $zone->event_id, 'fields' => ['name']]);
        });

        return back();
    }

    public function update(Request $r, Zone $zone): RedirectResponse
    {
        Gate::authorize('zones.gerer');
        $data = $r->validate(['name' => ['required', 'string', 'max:120', Rule::unique('zones')->where('event_id', $zone->event_id)->ignore($zone->id)]]);
        DB::transaction(function () use ($zone, $data): void {
            $zone->fill($data);
            $fields = array_keys($zone->getDirty());
            if (! $fields) {
                return;
            } $zone->save();
            app(Audit::class)->append('zone.modifiee', auth()->id(), ['id' => $zone->id, 'event_id' => $zone->event_id, 'fields' => $fields]);
        });

        return back();
    }
}
