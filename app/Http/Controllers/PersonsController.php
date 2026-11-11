<?php

namespace App\Http\Controllers;

use App\Services\Participations;
use App\Services\PersonRegistry;
use App\Services\Persons;
use App\Services\Vehicles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Registre des personnes : recherche, création explicite, fiche (historique), inscription à une activité et clôture d'une participation. */
final class PersonsController
{
    public function __construct(private readonly Persons $persons, private readonly Participations $participations, private readonly PersonRegistry $registry) {}

    /**
     * Registre unifié (lecture) : recherche, filtres, indicateurs et, sur demande (`personne`), la fiche en panneau.
     * Les propriétés lourdes sont paresseuses : le panneau se recharge seul (rechargement partiel).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('listes.importer');
        $filters = $request->validate([
            'q' => 'nullable|string|max:160', 'statut' => 'nullable|in:actif,archive', 'institutionnel' => ['nullable', 'regex:/^(aucun|AILCT-0[1-8])$/'],
            'habilitations' => 'nullable|in:avec,sans', 'personne' => 'nullable|uuid', 'page' => 'nullable|integer|min:1',
        ]);

        $page = null;
        $paginated = function () use (&$page, $filters) {
            return $page ??= $this->registry->list($filters);
        };

        return Inertia::render('Admin/Personnes', [
            'query' => (string) ($filters['q'] ?? ''),
            'filters' => ['statut' => $filters['statut'] ?? '', 'institutionnel' => $filters['institutionnel'] ?? '', 'habilitations' => $filters['habilitations'] ?? ''],
            'persons' => fn (): array => $paginated()->items(),
            'meta' => fn (): array => ['current_page' => $paginated()->currentPage(), 'last_page' => $paginated()->lastPage(), 'total' => $paginated()->total(),
                'from' => $paginated()->firstItem(), 'to' => $paginated()->lastItem()],
            'kpis' => fn (): array => $this->registry->kpis(),
            'institutionalOptions' => fn (): array => $this->registry->institutionalOptions(),
            'selected' => fn (): ?array => isset($filters['personne']) ? $this->registry->profile($filters['personne']) : null,
            'can' => ['habilitations' => $request->user()->can('zones.gerer')],
        ]);
    }

    /** Recherche pour sélectionner une personne connue (inscription, saisie d'un invité). */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('listes.importer');
        $query = (string) $request->validate(['q' => 'required|string|min:2|max:160'])['q'];

        return response()->json(['results' => $this->persons->search($query, 10)]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['first_name' => 'nullable|string|max:100', 'last_name' => 'nullable|string|max:100', 'full_name' => 'nullable|string|max:160', 'organisation' => 'nullable|string|max:160', 'homonym_confirmed' => 'sometimes|boolean']);
        $id = $this->persons->create($request->user(), $data, $request->boolean('homonym_confirmed'));

        return redirect('/administration/personnes/'.$id)->with('status', 'Personne enregistrée. Elle n’est inscrite à aucune activité : inscrivez-la ci-dessous.');
    }

    /** Correction d'identité : `persons` est la référence canonique (le profil permanent actif en est le miroir). */
    public function correct(Request $request, string $person): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['first_name' => 'nullable|string|max:100', 'last_name' => 'nullable|string|max:100', 'full_name' => 'nullable|string|max:160', 'organisation' => 'nullable|string|max:160', 'homonym_confirmed' => 'nullable|boolean']);
        $changed = $this->persons->correct($request->user(), $person, $data, $request->boolean('homonym_confirmed'));

        return back()->with('status', $changed === [] ? 'Aucune modification.' : 'Identité corrigée. Les participations, journaux, cartes et badges déjà émis ne sont pas réécrits.');
    }

    public function show(string $person): Response
    {
        Gate::authorize('listes.importer');
        $history = $this->persons->history($person);
        $taken = DB::table('visitors')->where('person_id', $person)->pluck('event_id')->all();
        $events = DB::table('events')->whereNull('anonymized_at')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['clos', 'archive']))
            ->whereNotIn('id', $taken)->orderByDesc('starts_at')->get(['id', 'name', 'kind', 'starts_at', 'ends_at']);

        // S09 : annonce de visite et véhicules de chaque participation de la personne (administrateur seulement).
        $visits = DB::table('visitors')->where('person_id', $person)->get(['id', 'expected_at', 'announced_at', 'no_show_reason'])->keyBy('id');
        $vehicles = DB::table('vehicles')->whereIn('visitor_id', $visits->keys())->whereIn('status', ['annonce', 'present'])->orderBy('plate')->get(['id', 'visitor_id', 'plate', 'kind', 'status'])->groupBy('visitor_id');

        return Inertia::render('Admin/Personne', [...$history, 'events' => $events,
            'visits' => $visits->map(fn ($v) => ['expected_at' => $v->expected_at, 'announced_at' => $v->announced_at, 'no_show_reason' => $v->no_show_reason, 'vehicles' => ($vehicles[$v->id] ?? collect())->map(fn ($x) => ['id' => $x->id, 'plate' => $x->plate, 'kind' => $x->kind, 'status' => $x->status])->values()->all()]),
            'vehicleKinds' => Vehicles::KINDS,
            'categories' => DB::table('categories')->whereIn('event_id', $events->pluck('id'))->orderBy('name')->get(['id', 'event_id', 'name']),
            'hosts' => DB::table('hosts')->whereIn('event_id', $events->pluck('id'))->orderBy('name')->get(['id', 'event_id', 'name'])]);
    }

    public function enrol(Request $request, string $person): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['event_id' => 'required|uuid', 'category_id' => 'required|uuid', 'host_id' => 'nullable|uuid', 'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date', 'retour' => 'nullable|in:activites']);
        $this->participations->enrol($request->user(), $person, Arr::except($data, ['retour']));
        // Retour à la fiche de l'activité quand l'inscription en vient : seule cette valeur est acceptée (jamais d'adresse libre).
        if (($data['retour'] ?? null) === 'activites') {
            return redirect('/administration/evenements?activite='.$data['event_id'].'&onglet=participants')->with('status', 'Participation enregistrée.');
        }

        return redirect('/administration/personnes/'.$person)->with('status', 'Participation enregistrée.');
    }

    public function close(Request $request, string $participation): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['reason' => 'required|string|max:200', 'retour' => 'nullable|in:activites']);
        $person = (string) DB::table('visitors')->where('id', $participation)->value('person_id');
        $event = (string) DB::table('visitors')->where('id', $participation)->value('event_id');
        $this->participations->close($request->user(), $participation, $data['reason']);
        if (($data['retour'] ?? null) === 'activites') {
            return redirect('/administration/evenements?activite='.$event.'&onglet=participants')->with('status', 'Participation clôturée. La personne et ses autres participations sont conservées.');
        }

        return redirect('/administration/personnes/'.$person)->with('status', 'Participation clôturée. La personne et ses autres participations sont conservées.');
    }
}
