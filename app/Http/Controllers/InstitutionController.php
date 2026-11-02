<?php

namespace App\Http\Controllers;

use App\Services\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Identité institutionnelle : écran d'administration et deux ressources publiques (feuille de couleurs, logo). */
final class InstitutionController
{
    public function index(Institution $institution): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Admin/Institution', [
            'form' => $institution->form(),
            'limits' => Institution::TEXTS,
            'defaults' => Institution::COLORS,
            'custom' => $institution->profile()['custom'],
            'hasLogo' => $institution->profile()['logoUrl'] !== null,
        ]);
    }

    public function update(Request $request, Institution $institution): RedirectResponse
    {
        $this->authorizeAdmin();
        $text = fn (int $max, bool $required = false) => [$required ? 'required' : 'nullable', 'string', 'max:'.$max, 'regex:/\A[^\x00-\x1F\x7F<>]*\z/u'];
        $data = $request->validate([
            'nom' => $text(Institution::TEXTS['nom']), 'nom_complet' => $text(Institution::TEXTS['nom_complet']), 'slogan' => $text(Institution::TEXTS['slogan']),
            'accueil_titre' => $text(Institution::TEXTS['accueil_titre']), 'accueil_description' => $text(Institution::TEXTS['accueil_description']),
            'connexion_accroche' => $text(Institution::TEXTS['connexion_accroche']), 'pied' => $text(Institution::TEXTS['pied']),
            'primary' => ['required', 'regex:/\A#[0-9A-Fa-f]{6}\z/'], 'secondary' => ['required', 'regex:/\A#[0-9A-Fa-f]{6}\z/'], 'accent' => ['required', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
        ]);
        $institution->save($data, $request->user());

        return back()->with('status', trim((string) ($data['nom'] ?? '')) === '' ? 'Aucun nom saisi : l’identité IKOMA ACCESS standard reste affichée.' : 'Identité enregistrée.');
    }

    public function home(Request $request, Institution $institution): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate(['actif' => ['required', 'boolean']]);
        $institution->setHome((bool) $data['actif'], $request->user());

        return back()->with('status', $data['actif'] ? 'Page d’accueil activée : elle présente les informations publiques de l’institution.' : 'Page d’accueil désactivée : l’adresse racine conduit à la connexion. Les contenus sont conservés.');
    }

    public function uploadLogo(Request $request, Institution $institution): RedirectResponse
    {
        $this->authorizeAdmin();
        $request->validate(['logo' => ['required', 'file', 'max:1024']]);
        $institution->storeLogo($request->file('logo'), $request->user());

        return back()->with('status', 'Logo enregistré.');
    }

    public function removeLogo(Request $request, Institution $institution): RedirectResponse
    {
        $this->authorizeAdmin();
        $institution->removeLogo($request->user());

        return back()->with('status', 'Logo retiré.');
    }

    public function reset(Request $request, Institution $institution): RedirectResponse
    {
        $this->authorizeAdmin();
        $request->validate(['confirmation' => ['required', 'in:RESTAURER IKOMA']]);
        $institution->reset($request->user());

        return back()->with('status', 'Identité IKOMA ACCESS standard restaurée.');
    }

    public function theme(Institution $institution): HttpResponse
    {
        return response($institution->themeCss(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(Institution $institution): BinaryFileResponse
    {
        abort_unless($institution->profile()['logoUrl'] !== null, 404);

        return response()->file($institution->logoPath(), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('comptes.gerer');
        abort_unless(request()->user()->hasRole('administrateur'), 403);
    }
}
