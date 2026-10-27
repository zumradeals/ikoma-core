<?php

namespace App\Http\Controllers;

use App\Services\Imports\ListImport;
use App\Services\VisitorSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ListImportsController
{
    public function index(Request $r, ListImport $imports): Response
    {
        Gate::authorize('listes.importer');
        $input = $r->validate(['event_id' => ['nullable', 'uuid', 'exists:events,id'], 'q' => ['nullable', 'string', 'max:160']]);
        $event = $input['event_id'] ?? null;

        return Inertia::render('Admin/Imports', ['events' => DB::table('events')->select('id', 'name')->orderBy('name')->get(), 'preview' => $event ? $imports->show($event, (string) $r->user()->id) : null, 'result' => $r->session()->get('import_result'), 'selectedEvent' => $event, 'results' => $event && ! empty($input['q']) ? app(VisitorSearch::class)->search($event, $input['q']) : []]);
    }

    public function preview(Request $r, ListImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $input = $r->validate(['event_id' => ['required', 'uuid', 'exists:events,id'], 'file' => ['required', 'file', 'max:5120'], 'create_hosts' => ['required', 'boolean']]);

        return $this->safe(function () use ($r, $imports, $input) {
            $imports->preview($input['event_id'], (string) $r->user()->id, $r->file('file'), (bool) $input['create_hosts']);

            return redirect('/administration/imports?event_id='.$input['event_id']);
        });
    }

    public function confirm(Request $r, ListImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $input = $r->validate(['event_id' => ['required', 'uuid', 'exists:events,id'], 'id' => ['required', 'uuid']]);

        return $this->safe(function () use ($r, $imports, $input) {
            $result = $imports->confirm($input['event_id'], (string) $r->user()->id, $input['id']);

            return redirect('/administration/imports?event_id='.$input['event_id'])->with('import_result', $result);
        });
    }

    public function abandon(Request $r, ListImport $imports): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $input = $r->validate(['event_id' => ['required', 'uuid', 'exists:events,id'], 'id' => ['required', 'uuid']]);

        return $this->safe(function () use ($r, $imports, $input) {
            $imports->abandon($input['event_id'], (string) $r->user()->id, $input['id']);

            return redirect('/administration/imports');
        });
    }

    public function template(): HttpResponse
    {
        Gate::authorize('listes.importer');

        return response("\xEF\xBB\xBFnom complet;organisation;hôte;catégorie;début de validité;fin de validité\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="modele-liste.csv"']);
    }

    /** Les exceptions SQL ne doivent jamais exposer les valeurs liées ni les copier aux logs. */
    private function safe(\Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return back()->withErrors(['file' => 'L’opération a échoué. Aucun visiteur n’a été ajouté. Réessayez ou contactez l’administrateur.']);
        }
    }
}
