<?php

namespace App\Http\Controllers;

use App\Services\HolderCards;
use App\Services\Imports\HolderImport;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Administration du registre des titulaires permanents (permission existante listes.importer : « Personnes et listes »). */
final class PermanentHoldersController
{
    public function index(Request $request, PermanentHolders $holders, InstitutionalCategories $categories, HolderImport $imports): Response
    {
        Gate::authorize('listes.importer');
        $status = in_array($request->query('statut'), ['actif', 'clos', 'tous'], true) ? (string) $request->query('statut') : 'actif';
        $photo = in_array($request->query('photo'), ['avec', 'sans'], true) ? (string) $request->query('photo') : '';
        $list = $holders->paginate($request->query('q'), $status, $request->query('categorie'), 25, $photo);

        return Inertia::render('Admin/Titulaires', [
            'holders' => $list,
            'filters' => ['q' => (string) $request->query('q', ''), 'statut' => $status, 'categorie' => (string) $request->query('categorie', ''), 'photo' => $photo],
            'importPreview' => $imports->show($request->user()),
            'bulkMax' => PermanentHolders::BULK_MAX,
            'categories' => $categories->all(),
            'identifierFormat' => $holders->format(),
            'identifierExample' => $holders->formatIdentifier(1),
            'permanentContext' => $holders->permanentContext(),
            'canConfigure' => $request->user()->can('categories.gerer'),
            'canCards' => $request->user()->can('badges.gerer'),
            'pipeline' => $request->user()->can('badges.gerer') ? app(HolderCards::class)->pipeline() : null,
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

    public function importPreview(Request $request, HolderImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $request->validate(['file' => ['required', 'file']]);
        $imports->preview($request->user(), $request->file('file'));

        return back()->with('status', 'Aperçu prêt : rien n’est encore enregistré. Relisez-le puis confirmez.');
    }

    public function importConfirm(Request $request, HolderImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['token' => ['required', 'uuid'], 'confirm_lines' => ['nullable', 'array', 'max:'.HolderImport::MAX_LINES], 'confirm_lines.*' => ['integer', 'min:1']]);
        $result = $imports->confirm($request->user(), $data['token'], $data['confirm_lines'] ?? []);

        return back()->with('status', $result['created'].' personne(s) enregistrée(s), '.$result['ignored'].' ligne(s) ignorée(s). Aucune carte n’a été créée.');
    }

    public function importAbandon(Request $request, HolderImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $imports->abandon($request->user(), (string) $request->validate(['token' => ['required', 'uuid']])['token']);

        return back()->with('status', 'Import abandonné : rien n’a été enregistré.');
    }

    public function importTemplate(): StreamedResponse
    {
        Gate::authorize('listes.importer');

        return response()->streamDownload(fn () => print HolderImport::template(), 'modele-import-personnes.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function bulkCategory(Request $request, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.PermanentHolders::BULK_MAX], 'ids.*' => ['uuid'], 'category' => ['nullable', 'string', 'max:20']]);
        $result = $holders->setCategoryMany($data['ids'], ($data['category'] ?? '') === '' ? null : $data['category'], $request->user());

        return back()->with('status', $result['modifies'].' personne(s) modifiée(s), '.$result['inchanges'].' déjà dans cette catégorie, '.$result['clos'].' close(s) ignorée(s). La catégorie ne donne aucun accès.');
    }

    public function bulkClose(Request $request, PermanentHolders $holders): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.PermanentHolders::BULK_MAX], 'ids.*' => ['uuid'], 'reason' => ['required', 'string', 'max:200'], 'confirmation' => ['required', 'accepted']]);
        $result = $holders->closeMany($data['ids'], $data['reason'], $request->user());

        return back()->with('status', $result['clos'].' personne(s) close(s) définitivement, '.$result['deja_clos'].' déjà close(s).');
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
