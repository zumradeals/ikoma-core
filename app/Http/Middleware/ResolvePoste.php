<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Associe la requête au poste choisi par la tablette (en-tête X-Poste-Id). L'enrôlement des tablettes viendra avec le lot L6. */
class ResolvePoste
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Poste-Id');
        $device = Str::isUuid($id) ? Device::find($id) : null;
        if ($device === null) {
            return response()->json(['code' => 'poste_requis', 'message' => 'Choisissez le poste d’accueil avant de continuer.', 'action' => null], 422);
        }
        $request->attributes->set('poste', $device);

        return $next($request);
    }
}
