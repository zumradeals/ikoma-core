<?php

namespace App\Http\Controllers;

use App\Services\BadgeRefused;
use App\Services\BadgeRegistry;
use App\Services\Poste\DeskRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Registre commun des badges : voir les badges disponibles de toutes les activités et les mettre à disposition d'une autre activité. */
final class BadgeRegistryController
{
    public function index(Request $r, BadgeRegistry $registry): Response
    {
        Gate::authorize('badges.gerer');
        $data = $r->validate(['activite' => 'nullable|uuid|exists:events,id']);
        $target = $data['activite'] ?? null;

        return Inertia::render('Admin/BadgeRegistre', [
            'target' => $target,
            'badges' => $registry->available($target),
            'events' => DB::table('events')->whereNotIn('status', ['clos', 'archive'])->whereNull('anonymized_at')->orderBy('name')->get(['id', 'name', 'kind']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'event_id', 'name', 'color']),
            'devices' => DB::table('devices')->whereNull('revoked_at')->orderBy('name')->get(['id', 'event_id', 'name', 'station']),
            'transfers' => DB::table('badge_transfers as t')->join('badges as b', 'b.id', '=', 't.badge_id')->join('events as f', 'f.id', '=', 't.from_event_id')->join('events as e', 'e.id', '=', 't.to_event_id')
                ->orderByDesc('t.created_at')->limit(30)->get(['t.id', 'b.code', 'f.name as from_name', 'e.name as to_name', 't.reservation_released', 't.created_at']),
        ]);
    }

    public function store(Request $r, BadgeRegistry $registry): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $data = $r->validate([
            'badges' => 'required|array|min:1|max:'.BadgeRegistry::MAX_PER_CALL, 'badges.*' => 'uuid|distinct',
            'event_id' => 'required|uuid|exists:events,id', 'category_id' => 'required|uuid|exists:categories,id', 'device_id' => 'nullable|uuid|exists:devices,id',
        ]);
        try {
            $count = $registry->transfer($data['badges'], $data['event_id'], $data['category_id'], $data['device_id'] ?? null, $r->user());
        } catch (BadgeRefused|DeskRefused $e) {
            return back()->withErrors(['badges' => $e->getMessage()]);
        }

        return back()->with('status', $count.' badge(s) mis à disposition de l’activité choisie. Synchronisez les postes pour les utiliser.');
    }
}
