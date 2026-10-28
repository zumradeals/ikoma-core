<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessAudit
{
    public function record(Request $request, Response $response): void
    {
        $denied = in_array($response->getStatusCode(), [401, 403, 429], true)
            || ($response->isRedirect() && parse_url($response->headers->get('Location', ''), PHP_URL_PATH) === '/login' && $request->path() !== 'login');
        if ($denied && ! $request->attributes->get('access_audited', false)) {
            $request->attributes->set('access_audited', true);
            // Gabarit de route uniquement : jamais de paramètre libre ou d'identité dans le journal.
            app(Audit::class)->append('acces.refuse', $request->user()?->id, ['route' => $request->route()?->uri() ?? 'inconnue', 'statut' => $response->getStatusCode()]);
        }
    }
}
