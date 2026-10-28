<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Le poste d'accueil est réservé au rôle agent_accueil : aucun autre rôle, même doté des permissions voisines, n'y accède. */
class EnsureAgentAccueil
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole('agent_accueil'), 403);

        return $next($request);
    }
}
