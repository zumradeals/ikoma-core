<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Page unique du contrôle sur site et de la sortie : même principe que le poste d'accueil (application Vue autonome, enrôlement et PIN partagés). */
final class ControleController
{
    public function __invoke(): Response
    {
        return Inertia::render('Controle');
    }
}
