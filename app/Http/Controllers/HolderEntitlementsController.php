<?php

namespace App\Http\Controllers;

use App\Services\HolderEntitlements;
use App\Services\PermanentAccess;
use App\Services\SiteEntitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Habilitations des titulaires permanents par zone et événement, politiques de zone, décisions d'accès et état des terminaux (A06).
 * Permissions existantes : « Personnes et listes » (listes.importer) et « Zones » (zones.gerer) pour les habilitations, « Zones » pour les politiques,
 * « Postes » (postes.gerer) pour la confiance hors ligne. Aucun rôle n'est ajouté.
 */
final class HolderEntitlementsController
{
    public function index(Request $request, HolderEntitlements $entitlements, PermanentAccess $access, SiteEntitlements $site): Response
    {
        $this->authorizeView();
        $events = DB::table('events')->where('is_permanent', false)->orderByDesc('starts_at')->get(['id', 'name']);
        $requested = $request->query('event');
        $eventId = is_string($requested) && $events->contains('id', $requested) ? $requested : ($events->first()->id ?? null);
        $trust = $access->offlineTrust();

        return Inertia::render('Admin/Habilitations', [
            'events' => $events, 'eventId' => $eventId,
            'site' => ['zones' => DB::table('zones')->whereNull('event_id')->orderBy('name')->get(['id', 'name']), 'entitlements' => $site->all()],
            'zones' => $eventId ? array_map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'classification' => $z->classification, 'permanent_allowed' => (bool) $z->permanent_allowed, 'offline_entry_allowed' => (bool) $z->offline_entry_allowed], $entitlements->zones($eventId)) : [],
            'entitlements' => $eventId ? $entitlements->forEvent($eventId) : [],
            'holders' => DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')->where('h.status', 'actif')->orderByRaw('public.ikoma_normalize(h.full_name)')->limit(500)
                ->get(['h.id', 'h.identifier', 'h.full_name', 'h.institutional_category as category_code', 'c.label as category_label']),
            'eventDates' => $eventId ? DB::table('events')->where('id', $eventId)->first(['starts_at', 'ends_at']) : null,
            'categories' => DB::table('institutional_categories')->orderBy('sort_order')->get(['code', 'label', 'color']),
            'decisions' => $eventId ? $this->decisions($eventId) : [],
            'devices' => $eventId ? $this->devices($eventId, $trust['effective']) : [],
            'trust' => $trust + ['max' => PermanentAccess::OFFLINE_MAX_MINUTES],
            'can' => ['grant' => $request->user()->can('listes.importer') && $request->user()->can('zones.gerer'), 'policies' => $request->user()->can('zones.gerer'), 'trust' => $request->user()->can('postes.gerer')],
        ]);
    }

    public function grant(Request $request, HolderEntitlements $entitlements): RedirectResponse
    {
        $this->authorizeGrant();
        $data = $request->validate(['holder_id' => ['required', 'uuid'], 'event_id' => ['required', 'uuid'], 'zone_id' => ['required', 'uuid'], 'valid_from' => ['required', 'date'], 'valid_until' => ['required', 'date']]);
        $entitlements->grant($data['holder_id'], $data['event_id'], $data['zone_id'], $data['valid_from'], $data['valid_until'], $request->user());

        return back()->with('status', 'Habilitation accordée.');
    }

    /** Accorde l'accès à plusieurs personnes et zones : « tout l'événement » (dates de l'événement) ou des dates précises. */
    public function grantMany(Request $request, HolderEntitlements $entitlements): RedirectResponse
    {
        $this->authorizeGrant();
        $data = $request->validate([
            'event_id' => ['required', 'uuid', Rule::exists('events', 'id')->where('is_permanent', false)],
            'holders' => ['required', 'array', 'min:1', 'max:200'], 'holders.*' => ['uuid', 'distinct'],
            'zones' => ['required', 'array', 'min:1', 'max:30'], 'zones.*' => ['uuid', 'distinct'],
            'whole_event' => ['required', 'boolean'],
            'valid_from' => ['required_if:whole_event,false', 'nullable', 'date'], 'valid_until' => ['required_if:whole_event,false', 'nullable', 'date'],
        ]);
        if ($data['whole_event']) {
            $event = DB::table('events')->where('id', $data['event_id'])->first(['starts_at', 'ends_at']);
            $from = (string) $event->starts_at;
            $until = (string) $event->ends_at;
        } else {
            $from = (string) $data['valid_from'];
            $until = (string) $data['valid_until'];
        }
        $result = $entitlements->grantMany($data['holders'], $data['event_id'], $data['zones'], $from, $until, $request->user());

        return back()->with('status', $result['accordees'].' accès accordé(s)'.($result['deja_couvertes'] > 0 ? ', '.$result['deja_couvertes'].' déjà en place (ignoré(s))' : '').'.');
    }

    public function revoke(Request $request, string $entitlement, HolderEntitlements $entitlements): RedirectResponse
    {
        $this->authorizeGrant();
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $entitlements->revoke($entitlement, $data['reason'], $request->user());

        return back()->with('status', 'Habilitation retirée : l’historique est conservé.');
    }

    /** Habilitations permanentes du SITE (par zone du site, sans événement). */
    public function grantSite(Request $request, SiteEntitlements $site): RedirectResponse
    {
        $this->authorizeGrant();
        $data = $request->validate(['holder_id' => ['required', 'uuid'], 'zone_id' => ['required', 'uuid'], 'valid_from' => ['required', 'date'], 'valid_until' => ['required', 'date']]);
        $site->grant($data['holder_id'], $data['zone_id'], $data['valid_from'], $data['valid_until'], $request->user());

        return back()->with('status', 'Habilitation du site accordée.');
    }

    public function revokeSite(Request $request, string $entitlement, SiteEntitlements $site): RedirectResponse
    {
        $this->authorizeGrant();
        $site->revoke($entitlement, $request->validate(['reason' => ['required', 'string', 'max:200']])['reason'], $request->user());

        return back()->with('status', 'Habilitation du site retirée : l’historique est conservé.');
    }

    public function policy(Request $request, string $zone, HolderEntitlements $entitlements): RedirectResponse
    {
        Gate::authorize('zones.gerer');
        $data = $request->validate(['event_id' => ['required', 'uuid', Rule::exists('events', 'id')->where('is_permanent', false)], 'classification' => ['required', 'string', 'max:40'], 'permanent_allowed' => ['required', 'boolean'], 'offline_entry_allowed' => ['required', 'boolean']]);
        $entitlements->savePolicy($data['event_id'], $zone, $data['classification'], (bool) $data['permanent_allowed'], (bool) $data['offline_entry_allowed'], $request->user());

        return back()->with('status', 'Politique de zone enregistrée.');
    }

    public function trust(Request $request, PermanentAccess $access): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:0', 'max:'.PermanentAccess::OFFLINE_MAX_MINUTES], 'approved' => ['required', 'boolean']]);
        $access->saveOfflineTrust((int) $data['minutes'], (bool) $data['approved'], $request->user());

        return back()->with('status', 'Confiance hors ligne enregistrée. Sans approbation, toute nouvelle entrée hors ligne reste refusée.');
    }

    /** @return list<array<string, mixed>> */
    private function decisions(string $eventId): array
    {
        return DB::table('access_events')->where('event_id', $eventId)->where('details->permanent', true)->orderByDesc('occurred_at')->orderByDesc('created_at')->limit(40)
            ->get(['id', 'type', 'checkpoint', 'occurred_at', 'details'])->map(function ($e) {
                $d = json_decode((string) $e->details, true) ?: [];

                return ['id' => $e->id, 'type' => $e->type, 'point' => $e->checkpoint, 'at' => $e->occurred_at, 'identifier' => $d['identifiant'] ?? null, 'verdict' => $e->type === 'sortie' ? 'sortie' : ($d['verdict'] ?? null), 'reason' => $d['raison'] ?? ($d['carte_statut'] ?? null), 'deferred' => (bool) ($d['differe'] ?? false)];
            })->all();
    }

    /** @return list<array<string, mixed>> */
    private function devices(string $eventId, int $trustMinutes): array
    {
        return DB::table('devices')->where('event_id', $eventId)->whereNull('revoked_at')->whereNotNull('token_hash')->orderBy('name')->get(['id', 'name', 'station', 'last_synced_at'])->map(function ($d) use ($trustMinutes) {
            $age = $d->last_synced_at === null ? null : (int) now()->diffInMinutes($d->last_synced_at, true);
            $state = $trustMinutes < 1 ? 'aucune_confiance' : ($age === null || $age > $trustMinutes ? 'confiance_expiree' : 'confiance_valide');

            return ['id' => $d->id, 'name' => $d->station ?? $d->name, 'last_synced_at' => $d->last_synced_at, 'age_minutes' => $age, 'state' => $state];
        })->all();
    }

    private function authorizeView(): void
    {
        abort_unless(request()->user()?->can('listes.importer') || request()->user()?->can('zones.gerer'), 403);
    }

    private function authorizeGrant(): void
    {
        Gate::authorize('listes.importer');
        Gate::authorize('zones.gerer');
    }
}
