<?php

namespace App\Http\Controllers;

use App\Enums\BadgeStatus;
use App\Services\SupportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BadgesController
{
    /**
     * Espace de travail « Badges et cartes » : registre commun, préparation d'un badge et aperçu, dans un seul écran. Lecture seule ici ; les actions passent par les contrats d'émission et d'impression.
     * Les anciennes adresses de filtrage des lots (statut, lot, réservation) mènent à la page historique des lots, qui reste disponible.
     */
    public function __invoke(Request $r, SupportRegistry $registry, BadgePreparerController $preparer): Response|RedirectResponse
    {
        Gate::authorize('badges.gerer');
        if ($r->hasAny(['status', 'batch', 'reserved'])) {
            return redirect('/administration/badges/lots'.($r->getQueryString() === null || $r->getQueryString() === '' ? '' : '?'.$r->getQueryString()));
        }
        $filters = $r->validate(['q' => 'nullable|string|max:160', 'type' => ['nullable', Rule::in(['carte_permanente', 'badge_temporaire'])], 'etat' => ['nullable', Rule::in(array_keys(SupportRegistry::STATES))], 'page' => 'nullable|integer|min:1']);

        return Inertia::render('Admin/BadgesCartes', [...$preparer->workspaceProps($r), 'registre' => $registry->list($filters, (int) ($filters['page'] ?? 1)), 'filters' => ['q' => $filters['q'] ?? '', 'type' => $filters['type'] ?? '', 'etat' => $filters['etat'] ?? ''],
            'states' => SupportRegistry::STATES]);
    }

    /** JSON : une page du registre commun (recherche, type, état). */
    public function supports(Request $r, SupportRegistry $registry): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $filters = $r->validate(['q' => 'nullable|string|max:160', 'type' => ['nullable', Rule::in(['carte_permanente', 'badge_temporaire'])], 'etat' => ['nullable', Rule::in(array_keys(SupportRegistry::STATES))], 'page' => 'nullable|integer|min:1']);

        return response()->json($registry->list($filters, (int) ($filters['page'] ?? 1)))->header('Cache-Control', 'no-store, private');
    }

    /** Page historique des lots, réservations et stock existants : conservée pour compatibilité et consultation, hors du parcours normal. */
    public function lots(Request $r): Response
    {
        Gate::authorize('badges.gerer');
        $filters = $r->validate(['status' => ['nullable', Rule::enum(BadgeStatus::class)], 'batch' => 'bail|nullable|uuid|exists:badge_batches,id', 'reserved' => ['nullable', Rule::in(['oui', 'non'])]]);
        $query = DB::table('badges as b')->join('badge_batches as batch', 'batch.id', '=', 'b.badge_batch_id')->leftJoin('devices as device', 'device.id', '=', 'b.reserved_device_id')->leftJoin('groups as grp', 'grp.id', '=', 'b.reserved_group_id')->leftJoin('categories as cat', 'cat.id', '=', 'b.category_id')
            // Cette page ne concerne que les badges des visiteurs : les cartes des personnes permanentes (contexte permanent) n'y figurent jamais.
            ->join('events as bev', 'bev.id', '=', 'batch.event_id')->where('bev.is_permanent', false);
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
        $batches = DB::table('badge_batches as batch')->leftJoin('categories as cat', 'cat.id', '=', 'batch.category_id')->join('events as ev', 'ev.id', '=', 'batch.event_id')->where('ev.is_permanent', false)
            ->selectRaw("batch.id, batch.name, batch.event_id, ev.name as event_name, batch.quantity, batch.margin, cat.name as category_name, (SELECT min($number) FROM badges WHERE badge_batch_id = batch.id) as first_number, (SELECT max($number) FROM badges WHERE badge_batch_id = batch.id) as last_number")
            ->orderBy('batch.name')->get();
        $setting = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');

        return Inertia::render('Admin/Badges', [
            'badges' => $query->orderBy('b.code')->paginate(30, ['b.id', 'b.code', 'b.status', 'batch.name as batch_name', 'device.station as reserved_station', 'grp.name as reserved_group', 'cat.name as category_name', 'cat.color as category_color', 'cat.priority_circuit as category_priority'])->withQueryString(),
            'batchCounts' => DB::table('badges')->selectRaw('badge_batch_id, status, count(*) as total, count(*) FILTER (WHERE reserved_device_id IS NOT NULL OR reserved_group_id IS NOT NULL) as reserved')->groupBy('badge_batch_id', 'status')->get()->groupBy('badge_batch_id'),
            'batches' => $batches,
            'statuses' => array_column(BadgeStatus::cases(), 'value'),
            'filters' => $filters,
            'events' => DB::table('events')->where('is_permanent', false)->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->orderBy('name')->get(['id', 'event_id', 'name', 'color']),
            'devices' => DB::table('devices')->orderBy('name')->get(['id', 'event_id', 'name', 'station']),
            'groups' => DB::table('groups')->orderBy('name')->get(['id', 'event_id', 'name']),
            'settings' => ['margin' => (int) $setting('badge_marge_pourcent'), 'reusable' => $setting('badge_reutilisable') === 'oui'],
        ]);
    }
}
