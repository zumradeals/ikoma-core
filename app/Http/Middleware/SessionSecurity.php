<?php

namespace App\Http\Middleware;

use App\Services\AccessAudit;
use App\Services\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SessionSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $minutes = (int) (DB::table('settings')->where('key', 'session_idle_minutes')->value('value') ?? 15);
            $last = (int) $request->session()->get('last_interaction', now()->timestamp);
            if (! $user->active || now()->timestamp - $last >= max(1, $minutes) * 60) {
                app(Audit::class)->append('session.expiree', $user->id);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['code' => 'session_expiree', 'message' => 'Votre session a expiré : reconnectez-vous.', 'action' => null], 401) : redirect('/login');
            }
            $request->session()->put('last_interaction', now()->timestamp);
        }
        if ($user && $user->requiresTwoFactor() && $request->isMethod('DELETE') && $request->is('user/two-factor-authentication')) {
            abort(403, 'La double authentification est obligatoire pour ce rôle.');
        }
        $response = $next($request);
        app(AccessAudit::class)->record($request, $response);
        if ($request->path() !== '/' && ! str_starts_with($request->path(), 'build/')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
