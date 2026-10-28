<?php

namespace App\Http\Middleware;

use App\Services\LoginAttempts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProgressiveLoginThrottle
{
    public function handle(Request $r, Closure $next): Response
    {
        if ($r->isMethod('POST') && $r->is('login', 'two-factor-challenge')) {
            $seconds = app(LoginAttempts::class)->remaining($r);
            if ($seconds > 0) {
                abort(429, 'Trop d’essais. Réessayez plus tard.', ['Retry-After' => (string) $seconds]);
            }
        }

        return $next($r);
    }
}
