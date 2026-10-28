<?php

namespace App\Http\Controllers;

use App\Services\Watchlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Écran de l'officier de sécurité : la liste d'interdits (audit global A2). Le motif n'est visible que sur cet écran. */
final class WatchlistController
{
    public function __construct(private readonly Watchlist $watchlist) {}

    public function index(): Response
    {
        Gate::authorize('interdits.gerer');

        return Inertia::render('Autorite/Interdits', ['entries' => $this->watchlist->entries()]);
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('interdits.gerer');
        $data = $r->validate(['full_name' => 'required|string|min:2|max:160', 'reason' => 'required|string|min:3|max:1000']);
        $this->watchlist->add($data['full_name'], $data['reason'], $r->user());

        return back()->with('status', 'Personne ajoutée à la liste d’interdits.');
    }

    public function lift(Request $r, string $entry): RedirectResponse
    {
        Gate::authorize('interdits.gerer');
        $done = $this->watchlist->requestLift($entry, $r->user());

        return back()->with('status', $done ? 'Interdiction levée.' : 'Demande envoyée : un administrateur doit valider la levée.');
    }
}
