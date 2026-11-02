<?php

namespace App\Http\Controllers;

use App\Services\Institution;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page d'accueil publique, configurable. Désactivée : l'adresse racine conduit à la connexion, sans rien supprimer.
 * La page ne reçoit que des informations publiques d'identité (Institution::shared) : aucune donnée opérationnelle, aucune session.
 */
final class HomeController
{
    public function show(Institution $institution): Response|RedirectResponse
    {
        if (! $institution->profile()['homeEnabled']) {
            return redirect('/login')->header('Cache-Control', 'no-store');
        }

        return Inertia::render('Home');
    }

    /** Coquille publique précachée par le service worker (secours hors ligne de l'accueil) : toujours servie, sans donnée de session. */
    public function shell(): Response
    {
        return Inertia::render('Home');
    }
}
