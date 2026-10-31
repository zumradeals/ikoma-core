<?php

namespace App\Http\Middleware;

use App\Services\FoundingModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureF06Active
{
    public function handle(Request $request, Closure $next): Response
    {
        app(FoundingModules::class)->requireF06();

        return $next($request);
    }
}
