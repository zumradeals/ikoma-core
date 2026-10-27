<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SessionSecurity;
use App\Services\Audit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class, SessionSecurity::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if (in_array($response->getStatusCode(), [401, 403, 429], true)) {
                app(Audit::class)->append('acces.refuse', auth()->id(), ['route' => request()->path(), 'statut' => $response->getStatusCode()]);
            }
            if (in_array($response->getStatusCode(), [403, 429], true) && ! request()->expectsJson()) {
                $response = Inertia::render('Error', ['status' => $response->getStatusCode()])->toResponse(request())->setStatusCode($response->getStatusCode());
            }
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
