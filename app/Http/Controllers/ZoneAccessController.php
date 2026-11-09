<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\ParticipationEntitlements;
use App\Services\ZoneAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accès aux zones (S06) : correspondance point de contrôle / zones et habilitations des participations. Administrateur seulement
 * (« zones.gerer », « listes.importer », « postes.gerer »). Le superviseur consulte les habilitations dans le centre de supervision (S07), sans les modifier.
 */
final class ZoneAccessController
{
    public function index(Request $request, ZoneAccess $access, ParticipationEntitlements $entitlements): Response
    {
        Gate::authorize('zones.gerer');
        $events = DB::table('events')->where('is_permanent', false)->orderByDesc('starts_at')->get(['id', 'name']);
        $requested = $request->query('event');
        $eventId = is_string($requested) && $events->contains('id', $requested) ? $requested : ($events->first()->id ?? null);
        $q = trim((string) $request->query('q', ''));
        $participants = [];
        if ($eventId !== null && mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $participants = DB::table('visitors')->where('event_id', $eventId)->whereNull('closed_at')->whereNull('withdrawn_at')->whereNull('holder_id')
                ->whereRaw('lower(public.ikoma_normalize(full_name)) LIKE public.ikoma_normalize(?)', [$like])->orderBy('full_name')->limit(20)->get(['id', 'full_name', 'organisation'])->all();
        }

        return Inertia::render('Admin/AccesZones', [
            'events' => $events, 'eventId' => $eventId, 'q' => $q,
            'zones' => $eventId ? DB::table('zones')->where('event_id', $eventId)->orderBy('name')->get(['id', 'name']) : [],
            'points' => DB::table('checkpoints')->where('active', true)->orderBy('code')->get(['id', DB::raw("CASE WHEN function_label IS NULL THEN name ELSE name || ' — ' || function_label END as name")]),
            'mappings' => $eventId ? $access->mappings($eventId) : [],
            'entitlements' => $eventId ? $entitlements->forEvent($eventId) : [],
            'participants' => $participants,
            'maxHours' => ParticipationEntitlements::maxExceptionalHours(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function map(Request $r, ZoneAccess $access): RedirectResponse
    {
        $data = $r->validate(['event_id' => 'required|uuid|exists:events,id', 'checkpoint_id' => 'required|uuid', 'zone_id' => 'required|uuid']);
        $access->map($data['event_id'], $data['checkpoint_id'], $data['zone_id'], $r->user());

        return back()->with('status', 'Correspondance enregistrée. Synchronisez les postes.');
    }

    public function unmap(Request $r, string $mapping, ZoneAccess $access): RedirectResponse
    {
        $access->unmap($mapping, $r->user());

        return back()->with('status', 'Correspondance retirée.');
    }

    public function grant(Request $r, ParticipationEntitlements $entitlements): RedirectResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'zone_id' => 'required|uuid', 'kind' => 'required|in:standard,exceptionnelle', 'reason' => 'nullable|string|max:200', 'valid_from' => 'required|date', 'valid_until' => 'required|date']);
        $entitlements->grant($data['visitor_id'], $data['zone_id'], $data['kind'], $data['reason'] ?? null, $data['valid_from'], $data['valid_until'], $r->user());

        return back()->with('status', 'Habilitation accordée. Synchronisez les postes.');
    }

    public function suspend(Request $r, string $entitlement, ParticipationEntitlements $entitlements): RedirectResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:200']);
        $entitlements->suspend($entitlement, $data['reason'], $r->user());

        return back()->with('status', 'Habilitation suspendue.');
    }

    public function resume(Request $r, string $entitlement, ParticipationEntitlements $entitlements): RedirectResponse
    {
        $entitlements->resume($entitlement, $r->user());

        return back()->with('status', 'Habilitation reprise.');
    }

    public function revoke(Request $r, string $entitlement, ParticipationEntitlements $entitlements): RedirectResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:200']);
        $entitlements->revoke($entitlement, $data['reason'], $r->user());

        return back()->with('status', 'Habilitation retirée.');
    }

    public function setting(Request $r, Audit $audit): RedirectResponse
    {
        abort_unless($r->user()->can('zones.gerer') && $r->user()->can('listes.importer'), 403);
        $data = $r->validate(['max_heures' => 'required|integer|min:1|max:168']);
        DB::table('settings')->updateOrInsert(['key' => 'habilitation_exceptionnelle_max_heures'], ['value' => (string) $data['max_heures']]);
        $audit->append('habilitation.duree_maximale_modifiee', $r->user()->id, ['heures' => (int) $data['max_heures']]);

        return back()->with('status', 'Durée maximale enregistrée.');
    }
}
