<?php

namespace App\Http\Middleware;

use App\Services\DemoLab;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Le laboratoire de démonstration n'existe pas hors des environnements prévus pour les essais : la route elle-même répond « introuvable ». */
final class EnsureDemoLab
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DemoLab::available(), 404);

        return $next($request);
    }
}
