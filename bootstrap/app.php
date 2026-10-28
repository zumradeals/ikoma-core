<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProgressiveLoginThrottle;
use App\Http\Middleware\ResolvePoste;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionSecurity;
use App\Services\AccessAudit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Le conteneur nginx n'écoute que sur 127.0.0.1 : seul le proxy HTTPS du serveur (Caddy) peut l'atteindre, on lui fait donc confiance pour le schéma https.
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ProgressiveLoginThrottle::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ResolvePoste::class);
        $middleware->web(append: [HandleInertiaRequests::class, SessionSecurity::class, ProgressiveLoginThrottle::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error): bool {
            // Aucun message/trace/SQL brut : ils peuvent contenir des identités, mots de passe ou jetons.
            Log::error('Erreur applicative', ['type' => $error::class, 'sqlstate' => $error instanceof QueryException ? $error->getCode() : null, 'reference' => (string) Str::uuid()]);

            return false;
        });
        $exceptions->respond(function (Response $response) {
            app(AccessAudit::class)->record(request(), $response);
            if ($response->getStatusCode() >= 500) {
                $status = $response->getStatusCode();
                $response = request()->expectsJson() ? response()->json(['message' => 'Service momentanément indisponible. Réessayez ou contactez le superviseur.'], $status) : Inertia::render('Error', ['status' => $status])->toResponse(request())->setStatusCode($status);
            }
            if ($response->getStatusCode() === 404 && request()->expectsJson()) {
                $response = response()->json(['message' => 'Ressource introuvable.'], 404);
            }

            if (in_array($response->getStatusCode(), [403, 429], true) && ! request()->expectsJson()) {
                $retryAfter = $response->headers->get('Retry-After');
                $response = Inertia::render('Error', ['status' => $response->getStatusCode(), 'retryAfter' => max(1, (int) ($retryAfter ?? 60))])->toResponse(request())->setStatusCode($response->getStatusCode());
                if ($retryAfter !== null) {
                    $response->headers->set('Retry-After', $retryAfter);
                }
            }
            $response->headers->set('Cache-Control', 'no-store, private');

            return SecurityHeaders::apply(request(), $response);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
