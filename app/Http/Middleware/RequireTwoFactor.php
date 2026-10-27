<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->requiresTwoFactor() && ! $user->two_factor_confirmed_at) {
            return redirect('/securite');
        }

        return $next($request);
    }
}
