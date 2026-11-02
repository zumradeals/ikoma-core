<?php

namespace App\Http\Controllers;

use App\Services\CardLifecycle;
use App\Services\CardModels;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\Institution;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
            'card' => $card, 'cards' => app(CardLifecycle::class)->history($holder), 'reasons' => CardLifecycle::REASONS, 'entitlements' => app(HolderEntitlements::class)->forHolder($holder), 'preview' => $html, 'permanentContext' => $holders->permanentContext() !== null,
            'provisional' => $row->institutional_category === null || ! app(CardModels::class)->forCategory($row->institutional_category)['validated'],
            'csrf' => csrf_token(),
        ])->withViewData([]);
    }

    public function issue(Request $request, string $holder, HolderCards $cards): RedirectResponse
    {
        $this->authorizeCard();
        $cards->requirePhoto($holder);
        // Carte préparée, pas encore remise : elle n'ouvre rien tant qu'elle n'est pas activée (bouton « Remettre et activer »).
        $cards->issue($holder, $request->user(), false);

        return redirect('/administration/titulaires/'.$holder.'/carte')->with('status', 'Carte préparée. Imprimez-la dans l’Atelier des badges, puis remettez-la.');
    }

    /** « Tout préparer » : prépare les cartes de toutes les personnes (avec photo et catégorie) qui n'en ont pas encore. */
    public function prepareAll(Request $request, HolderCards $cards): RedirectResponse
    {
        $this->authorizeCard();
        $result = $cards->prepareAll($request->user());

        return redirect('/administration/titulaires')->with('status', $result['preparees'].' carte(s) préparée(s)'.($result['restantes'] > 0 ? ', '.$result['restantes'].' restante(s) : recommencez' : '').'. Imprimez-les dans l’Atelier des badges.');
    }

    public function activate(Request $request, string $holder, HolderCards $cards): RedirectResponse
    {
        $this->authorizeCard();
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $cards->activate($holder, $request->user());

        return redirect('/administration/titulaires/'.$holder.'/carte')->with('status', 'Carte remise et activée : elle peut maintenant être utilisée au contrôle (selon les habilitations).');
    }

    public function revoke(Request $request, string $holder, CardLifecycle $lifecycle): RedirectResponse
    {
        $this->authorizeCard();
        $data = $request->validate(['reason' => ['required', 'string', 'in:'.implode(',', array_keys(CardLifecycle::REASONS))], 'note' => ['nullable', 'string', 'max:200'], 'confirmation' => ['required', 'accepted']]);
        $lifecycle->revoke($holder, $data['reason'], $data['note'] ?? null, $request->user());

        return redirect('/administration/titulaires/'.$holder.'/carte')->with('status', 'Carte révoquée : elle est refusée partout, immédiatement et définitivement. Remplacez-la pour émettre une nouvelle carte.');
    }

    public function replace(Request $request, string $holder, CardLifecycle $lifecycle): RedirectResponse
    {
        $this->authorizeCard();
        $data = $request->validate(['reason' => ['required', 'string', 'in:'.implode(',', array_keys(CardLifecycle::REASONS))], 'note' => ['nullable', 'string', 'max:200'], 'confirmation' => ['required', 'accepted']]);
        app(HolderCards::class)->requirePhoto($holder);
        $lifecycle->replace($holder, $data['reason'], $data['note'] ?? null, $request->user(), false);

        return redirect('/administration/titulaires/'.$holder.'/carte')->with('status', 'Nouvelle carte préparée. L’ancienne est révoquée ; le titulaire, son identifiant et ses habilitations sont inchangés.');
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

    /** Page « Personnalisation des cartes » : un modèle à la fois, choisi parmi les huit catégories. */
    public function models(Request $request, HolderCards $cards, InstitutionalCategories $categories, CardModels $models): \Inertia\Response
    {
        Gate::authorize('badges.gerer');
        $all = $categories->all();
        $codes = array_map(fn ($c) => $c->code, $all);
        $selected = in_array($request->query('categorie'), $codes, true) ? (string) $request->query('categorie') : ($codes[0] ?? null);
        $model = $selected === null ? null : $models->forCategory($selected);
        $preview = null;
        if ($selected !== null) {
            $category = collect($all)->firstWhere('code', $selected);
            $data = $cards->specimen($category);
            $preview = ['recto' => view('badges.holder-card-recto', ['card' => $data])->render(), 'verso' => view('badges.holder-card-verso', ['card' => $data])->render()];
        }

        return Inertia::render('Admin/PersonnalisationCartes', [
            'categories' => array_map(fn ($c) => ['code' => $c->code, 'label' => $c->label], $all), 'selected' => $selected, 'model' => $model, 'preview' => $preview,
            'settings' => $cards->settings(), 'limits' => ['ligne1' => 80, 'ligne2' => 80, 'contact' => 80, 'avis' => 160],
            'initialized' => $categories->initialized(), 'canConfigure' => $request->user()->can('categories.gerer'),
        ]);
    }

    /** Aperçu en direct d'un modèle avec des valeurs pas encore enregistrées : exemple fictif, aucun vrai QR. */
    public function modelPreview(Request $request, string $code, HolderCards $cards, CardModels $models): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $category = DB::table('institutional_categories')->where('code', $code)->first();
        abort_if($category === null, 404);
        $override = $models->clean($code, $request->all());
        $data = $cards->specimen($category, $override);

        return response()->json(['recto' => view('badges.holder-card-recto', ['card' => $data])->render(), 'verso' => view('badges.holder-card-verso', ['card' => $data])->render()])->header('Cache-Control', 'no-store, private');
    }

    public function saveModel(Request $request, string $code, CardModels $models): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        abort_unless(DB::table('institutional_categories')->where('code', $code)->exists(), 404);
        $models->save($code, $request->all(), $request->user());

        return redirect('/administration/titulaires/modeles?categorie='.$code)->with('status', 'Modèle enregistré. Il est « à valider » avant de servir de modèle officiel.');
    }

    public function validateModel(Request $request, string $code, CardModels $models): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        abort_unless(DB::table('institutional_categories')->where('code', $code)->exists(), 404);
        $models->validate($code, $request->user());

        return redirect('/administration/titulaires/modeles?categorie='.$code)->with('status', 'Modèle validé.');
    }

    public function settings(Request $request, HolderCards $cards): RedirectResponse
    {
        Gate::authorize('categories.gerer');
        $data = $request->validate(['ligne1' => 'nullable|string|max:80', 'ligne2' => 'nullable|string|max:80', 'contact' => 'nullable|string|max:80', 'avis' => 'nullable|string|max:160']);
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

    public function portraitImage(string $holder, HolderCards $cards): Response
    {
        Gate::authorize('listes.importer');
        $jpeg = $cards->portraitBytes($holder);
        abort_if($jpeg === null, 404);

        return response($jpeg)->header('Content-Type', 'image/jpeg')->header('Cache-Control', 'no-store, private')->header('Content-Disposition', 'inline')->header('X-Content-Type-Options', 'nosniff');
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
