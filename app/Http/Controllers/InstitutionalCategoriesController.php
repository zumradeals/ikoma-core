<?php

namespace App\Http\Controllers;

use App\Services\InstitutionalCategories;
use App\Services\Pilotage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Référentiel des catégories institutionnelles : libellés, couleurs, repères et liens facultatifs vers les catégories d'événement. Aucun droit d'accès. */
final class InstitutionalCategoriesController
{
    public function index(Request $request, InstitutionalCategories $categories): Response
    {
        Gate::authorize('categories.gerer');
        $event = app(Pilotage::class)->event(is_string($request->query('event')) ? $request->query('event') : null);
        $events = DB::table('events')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'ouvert'))->orderByDesc('starts_at')->get(['id', 'name']);

        return Inertia::render('Admin/CategoriesInstitutionnelles', [
            'categories' => $categories->all(),
            'initialized' => $categories->initialized(),
            'events' => $events,
            'eventId' => $event?->id,
            'eventCategories' => $event === null ? [] : DB::table('categories')->where('event_id', $event->id)->orderBy('name')->get(['id', 'name', 'color', 'institutional_code']),
            'neutralColor' => InstitutionalCategories::NEUTRAL_COLOR,
        ]);
    }

    public function initialize(Request $request, InstitutionalCategories $categories): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $created = $categories->initialize($request->user());

        return back()->with('status', $created > 0 ? $created.' catégorie(s) institutionnelle(s) créée(s) avec des valeurs neutres.' : 'Les huit catégories existent déjà.');
    }

    public function update(Request $request, string $code, InstitutionalCategories $categories): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80', 'regex:/\A[^\x00-\x1F\x7F<>]+\z/u'],
            'color' => ['required', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'symbol' => ['required', 'string', 'max:8', 'regex:/\A[^\x00-\x1F\x7F<>]+\z/u'],
        ]);
        $categories->update($code, $data, $request->user());

        return back()->with('status', 'Catégorie institutionnelle enregistrée.');
    }

    public function link(Request $request, InstitutionalCategories $categories): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $request->validate(['event_id' => ['required', 'uuid'], 'category_id' => ['required', 'uuid'], 'code' => ['nullable', 'string', 'max:12']]);
        $categories->link($data['event_id'], $data['category_id'], ($data['code'] ?? '') === '' ? null : $data['code'], $request->user());

        return back()->with('status', 'Association enregistrée. Elle n’accorde aucun droit d’accès : les zones restent celles de la catégorie d’événement.');
    }
}
