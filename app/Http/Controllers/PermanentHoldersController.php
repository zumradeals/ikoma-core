<?php

namespace App\Http\Controllers;

use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Administration du registre des titulaires permanents (permission existante listes.importer : « Personnes et listes »). */
final class PermanentHoldersController
{
    public function index(Request $request, PermanentHolders $holders, InstitutionalCategories $categories): Response
    {
        Gate::authorize('listes.importer');
        $status = in_array($request->query('statut'), ['actif', 'clos', 'tous'], true) ? (string) $request->query('statut') : 'actif';
        $list = $holders->paginate($request->query('q'), $status, $request->query('categorie'));

        return Inertia::render('Admin/Titulaires', [
            'holders' => $list,
            'filters' => ['q' => (string) $request->query('q', ''), 'statut' => $status, 'categorie' => (string) $request->query('categorie', '')],
            'categories' => $categories->all(),
            'identifierFormat' => $holders->format(),
            'identifierExample' => $holders->formatIdentifier(1),
            'permanentContext' => $holders->permanentContext(),
            'canConfigure' => $request->user()->can('categories.gerer'),
            'canCards' => $request->user()->can('badges.gerer'),
        ]);
    }

    public function store(Request $request, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $holders->create($this->input($request), $request->user(), $request->boolean('confirm_homonym'));

        return back()->with('status', 'Titulaire enregistré.');
    }

    public function update(Request $request, string $holder, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $changed = $holders->update($holder, $this->input($request), $request->user(), $request->boolean('confirm_homonym'));

        return back()->with('status', $changed === [] ? 'Aucune modification.' : 'Titulaire modifié. L’identifiant imprimable est inchangé.');
    }

    public function close(Request $request, string $holder, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $request->validate(['reason' => ['required', 'string', 'max:200'], 'confirmation' => ['required', 'accepted']]);
        $holders->close($holder, (string) $request->input('reason'), $request->user());

        return back()->with('status', 'Titulaire clos. Son identifiant reste réservé et n’est jamais réattribué.');
    }

    public function format(Request $request, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $request->validate(['prefix' => ['nullable', 'regex:/\A[A-Z0-9]{0,8}\z/'], 'width' => ['required', 'integer', 'between:4,12'], 'check' => ['required', 'boolean']]);
        $holders->saveFormat(['prefix' => (string) ($data['prefix'] ?? ''), 'width' => (int) $data['width'], 'check' => (bool) $data['check']], $request->user());

        return back()->with('status', 'Format enregistré pour les prochains identifiants. Les identifiants déjà émis ne changent pas.');
    }

    public function createContext(Request $request, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('evenements.gerer');
        $holders->createPermanentContext($request->user());

        return back()->with('status', 'Contexte permanent créé.');
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return $request->only(PermanentHolders::FIELDS);
    }
}
