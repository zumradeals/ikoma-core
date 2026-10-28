<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class TwoFactorRecovery
{
    public function reset(User $actor, User $target, string $fingerprint, string $password): void
    {
        abort_unless($actor->can('comptes.gerer') && $actor->hasRole('administrateur') && $actor->id !== $target->id, 403);
        DB::transaction(function () use ($actor, $target, $fingerprint, $password): void {
            DB::statement('SELECT pg_advisory_xact_lock(731903)');
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->active && $actor->hasRole('administrateur') && $actor->can('comptes.gerer') && $actor->two_factor_confirmed_at !== null, 403);
            if (! Hash::check($password, $actor->password)) {
                throw ValidationException::withMessages(['password' => 'Confirmation non acceptée.']);
            }
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless(hash_equals($fingerprint, $this->fingerprint($target)), 409, 'Recommencez la demande de réinitialisation.');
            abort_unless($target->requiresTwoFactor(), 422, 'Ce compte ne nécessite pas cette procédure.');
            $this->clear($target);
            app(Audit::class)->append('compte.2fa_reinitialisee', $actor->id, ['user_id' => $target->id]);
        });
    }

    public function emergency(string $id): void
    {
        DB::transaction(function () use ($id): void {
            DB::statement('SELECT pg_advisory_xact_lock(731903)');
            // Verrou partagé avec les mutations de comptes : la condition « dernier » ne se décide pas sur une copie.
            $users = User::query()->orderBy('id')->lockForUpdate()->get();
            $admins = $users->filter(fn (User $u) => $u->active && $u->hasRole('administrateur'));
            abort_unless($admins->count() === 1 && $admins->first()->id === $id, 422, 'La procédure est réservée au dernier administrateur actif.');
            $this->clear($admins->first());
            app(Audit::class)->append('compte.bris_de_glace', null, ['user_id' => $id]);
        });
    }

    public function fingerprint(User $user): string
    {
        return hash('sha256', (string) $user->two_factor_secret.'|'.$user->updated_at.'|'.$user->active.'|'.$user->getRoleNames()->sort()->implode(','));
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'remember_token' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
