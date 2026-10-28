<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Page unique du poste d'accueil : une application Vue autonome, sans visite Inertia pendant le parcours (plan, section 4.1). */
final class PosteController
{
    public function __invoke(): Response
    {
        return Inertia::render('Poste');
    }
}
