<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactorRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class TwoFactorRecoveryController
{
    public function prepare(Request $r, User $user): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        abort_unless($r->user()->hasRole('administrateur') && $r->user()->id !== $user->id, 403);
        abort_unless($user->requiresTwoFactor(), 422);
        $token = Str::random(64);
        $r->session()->put('2fa_reset', ['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires' => now()->addMinutes(5)->timestamp, 'fingerprint' => app(TwoFactorRecovery::class)->fingerprint($user)]);
        app(Audit::class)->append('compte.2fa_reinitialisation_demandee', $r->user()->id, ['user_id' => $user->id]);

        return back()->with('twoFactorReset', ['user_id' => $user->id, 'token' => $token]);
    }

    public function confirm(Request $r, User $user, TwoFactorRecovery $recovery): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        $data = $r->validate(['token' => 'required|string|size:64', 'password' => 'required|string', 'confirmation' => 'accepted']);
        $challenge = $r->session()->pull('2fa_reset');
        abort_unless(is_array($challenge) && $challenge['user_id'] === $user->id && $challenge['expires'] > now()->timestamp && hash_equals($challenge['token_hash'], hash('sha256', $data['token'])) && hash_equals($challenge['fingerprint'], app(TwoFactorRecovery::class)->fingerprint($user)), 409, 'Recommencez la demande de réinitialisation.');
        $recovery->reset($r->user(), $user, $challenge['fingerprint'], $data['password']);

        return back();
    }
}
