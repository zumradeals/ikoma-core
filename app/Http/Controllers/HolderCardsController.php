<?php

namespace App\Http\Controllers;

use App\Services\HolderCards;
use App\Services\Institution;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Chaîne de production des cartes institutionnelles : titulaire → préparation → aperçu → génération/impression.
 * Permissions existantes : « Personnes et listes » (listes.importer) pour le titulaire, « Badges » (badges.gerer) pour la carte.
 * Une carte n'accorde aucun accès : voir HolderCards.
 */
final class HolderCardsController
{
    public function show(Request $request, string $holder, PermanentHolders $holders, HolderCards $cards): \Inertia\Response
    {
        $this->authorizeCard();
        $row = $holders->find($holder);
        abort_if($row === null, 404);
        $card = $cards->activeCard($holder);
        $html = null;
        if ($card !== null && $row->status === 'actif') {
            $data = $cards->cardData($holder);
            $cards->recordPreview($holder, $request->user());
            $html = ['recto' => view('badges.holder-card-recto', ['card' => $data])->render(), 'verso' => view('badges.holder-card-verso', ['card' => $data])->render()];
        }
        $category = $row->institutional_category === null ? null : collect(app(InstitutionalCategories::class)->all())->firstWhere('code', $row->institutional_category);

        return Inertia::render('Admin/CarteTitulaire', [
            'holder' => ['id' => $row->id, 'identifier' => $row->identifier, 'full_name' => $row->full_name, 'status' => $row->status, 'institutional_category' => $row->institutional_category, 'has_photo' => $row->photo_file !== null, 'photo_print_allowed' => (bool) $row->photo_print_allowed],
            'category' => $category === null ? null : ['code' => $category->code, 'label' => $category->label],
            'card' => $card, 'preview' => $html, 'permanentContext' => $holders->permanentContext() !== null,
            'provisional' => ! $cards->settings()['valide'],
            'csrf' => csrf_token(),
        ])->withViewData([]);
    }

    public function issue(Request $request, string $holder, HolderCards $cards): RedirectResponse
    {
        $this->authorizeCard();
        $cards->issue($holder, $request->user());

        return redirect('/administration/titulaires/'.$holder.'/carte')->with('status', 'Carte préparée. Vérifiez l’aperçu avant de l’imprimer.');
    }

    public function print(Request $request, string $holder, HolderCards $cards): Response
    {
        $this->authorizeCard();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $card = $cards->cardData($holder);
        $kind = $cards->recordPrint($holder, $data['reason'] ?? null, $request->user());
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);

        return response()->view('badges.holder-card-print', [
            'card' => $card, 'kind' => $kind, 'alignment' => false, 'nonce' => $nonce, 'institution' => app(Institution::class)->profile()['name'],
            'back' => '/administration/titulaires/'.$holder.'/carte',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    /** Page d'essai : aucun QR, aucune donnée personnelle ; ne crée aucun enregistrement. */
    public function alignment(Request $request): Response
    {
        Gate::authorize('badges.gerer');
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);

        return response()->view('badges.holder-card-print', [
            'card' => null, 'kind' => null, 'alignment' => true, 'nonce' => $nonce, 'institution' => app(Institution::class)->profile()['name'], 'back' => '/administration/titulaires/modeles',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function models(HolderCards $cards, InstitutionalCategories $categories): \Inertia\Response
    {
        Gate::authorize('badges.gerer');
        $specimens = [];
        foreach ($categories->all() as $category) {
            $data = $cards->specimen($category);
            $specimens[] = ['code' => $category->code, 'label' => $category->label, 'recto' => view('badges.holder-card-recto', ['card' => $data])->render(), 'verso' => view('badges.holder-card-verso', ['card' => $data])->render()];
        }

        return Inertia::render('Admin/ModelesCartes', [
            'specimens' => $specimens, 'settings' => $cards->settings(), 'limits' => ['ligne1' => 80, 'ligne2' => 80, 'contact' => 80, 'avis' => 160],
            'initialized' => $categories->initialized(), 'canConfigure' => request()->user()->can('categories.gerer'),
        ]);
    }

    public function settings(Request $request, HolderCards $cards): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $request->validate(['ligne1' => 'nullable|string|max:80', 'ligne2' => 'nullable|string|max:80', 'contact' => 'nullable|string|max:80', 'avis' => 'nullable|string|max:160', 'valide' => 'boolean']);
        $cards->saveSettings($data, $request->user());

        return back()->with('status', 'Mentions du verso enregistrées.');
    }

    public function portrait(Request $request, string $holder, HolderCards $cards): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $request->validate(['photo' => 'nullable|file|max:5120', 'print_allowed' => 'boolean']);
        $cards->savePortrait($holder, $request->file('photo'), false, $request->boolean('print_allowed'), $request->user());

        return back()->with('status', 'Portrait enregistré.');
    }

    public function removePortrait(Request $request, string $holder, HolderCards $cards): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $cards->savePortrait($holder, null, true, false, $request->user());

        return back()->with('status', 'Portrait retiré.');
    }

    private function authorizeCard(): void
    {
        Gate::authorize('listes.importer');
        Gate::authorize('badges.gerer');
    }
}
