<?php

namespace App\Http\Controllers;

use App\Services\Installation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Assistant d'initialisation à usage unique (L12). Introuvable (404) dès qu'il n'est plus disponible ; chaque accès refusé est journalisé. */
final class InstallationController
{
    public function __construct(private readonly Installation $installation) {}

    public function show(Request $r): Response
    {
        $this->guard($r);

        return Inertia::render('Installation', ['checks' => $this->installation->checks($r->isSecure()), 'ready' => $this->installation->ready($r->isSecure())]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->guard($r);
        abort_unless($this->installation->ready($r->isSecure()), 422, 'Les vérifications obligatoires ne sont pas toutes réussies.');
        $data = $r->validate([
            'token' => 'required|string|max:200',
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:180|unique:users',
            'password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
            'event_name' => 'required|string|max:160',
            'site' => 'nullable|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
        ]);
        if (! $this->installation->tokenMatches($data['token'])) {
            $this->installation->logRefusedAccess((string) $r->ip());

            return back()->withErrors(['token' => 'Ce jeton d’installation n’est pas le bon. Il est affiché à la fin de install.sh.']);
        }
        $this->installation->install($data);

        return redirect('/login')->with('status', 'Installation terminée. Connectez-vous avec le compte administrateur : l’authentification à deux facteurs vous sera demandée dès la première connexion.');
    }

    private function guard(Request $r): void
    {
        if (! $this->installation->available()) {
            $this->installation->logRefusedAccess((string) $r->ip());
            abort(404);
        }
    }
}
