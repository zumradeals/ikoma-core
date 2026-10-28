<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TwoFactorRecovery
{
    public function reset(User $actor, User $target): void
    {
        abort_unless($actor->can('comptes.gerer') && $actor->hasRole('administrateur') && $actor->id !== $target->id, 403);
        DB::transaction(function () use ($actor, $target): void {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($target->requiresTwoFactor(), 422, 'Ce compte ne nécessite pas cette procédure.');
            $this->clear($target);
            app(Audit::class)->append('compte.2fa_reinitialisee', $actor->id, ['user_id' => $target->id]);
        });
    }

    public function emergency(string $id): void
    {
        DB::transaction(function () use ($id): void {
            // Verrou partagé avec les mutations de comptes : la condition « dernier » ne se décide pas sur une copie.
            $users = User::query()->orderBy('id')->lockForUpdate()->get();
            $admins = $users->filter(fn (User $u) => $u->active && $u->hasRole('administrateur'));
            abort_unless($admins->count() === 1 && $admins->first()->id === $id, 422, 'La procédure est réservée au dernier administrateur actif.');
            $this->clear($admins->first());
            app(Audit::class)->append('compte.bris_de_glace', null, ['user_id' => $id]);
        });
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'remember_token' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
