<?php

namespace App\Http\Controllers;

use App\Services\Participations;
use App\Services\Persons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Registre des personnes : recherche, création explicite, fiche (historique), inscription à une activité et clôture d'une participation. */
final class PersonsController
{
    public function __construct(private readonly Persons $persons, private readonly Participations $participations) {}

    public function index(Request $request): Response
    {
        Gate::authorize('listes.importer');
        $query = (string) ($request->validate(['q' => 'nullable|string|max:160'])['q'] ?? '');

        return Inertia::render('Admin/Personnes', ['query' => $query, 'persons' => $this->persons->search($query, 50)]);
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

    public function show(string $person): Response
    {
        Gate::authorize('listes.importer');
        $history = $this->persons->history($person);
        $taken = DB::table('visitors')->where('person_id', $person)->pluck('event_id')->all();
        $events = DB::table('events')->where('is_permanent', false)->whereNull('anonymized_at')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['clos', 'archive']))
            ->whereNotIn('id', $taken)->orderByDesc('starts_at')->get(['id', 'name', 'kind', 'starts_at', 'ends_at']);

        return Inertia::render('Admin/Personne', [...$history, 'events' => $events,
            'categories' => DB::table('categories')->whereIn('event_id', $events->pluck('id'))->orderBy('name')->get(['id', 'event_id', 'name']),
            'hosts' => DB::table('hosts')->whereIn('event_id', $events->pluck('id'))->orderBy('name')->get(['id', 'event_id', 'name'])]);
    }

    public function enrol(Request $request, string $person): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['event_id' => 'required|uuid', 'category_id' => 'required|uuid', 'host_id' => 'nullable|uuid', 'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date']);
        $this->participations->enrol($request->user(), $person, $data);

        return redirect('/administration/personnes/'.$person)->with('status', 'Participation enregistrée.');
    }

    public function close(Request $request, string $participation): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['reason' => 'required|string|max:200']);
        $person = (string) DB::table('visitors')->where('id', $participation)->value('person_id');
        $this->participations->close($request->user(), $participation, $data['reason']);

        return redirect('/administration/personnes/'.$person)->with('status', 'Participation clôturée. La personne et ses autres participations sont conservées.');
    }
}
