<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountsController
{
    public function index(): Response
    {
        Gate::authorize('comptes.gerer');

        return Inertia::render('Admin/Accounts', ['users' => User::with('roles')->orderBy('name')->get()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => $u->active, 'role' => $u->getRoleNames()->first()]), 'roles' => array_keys(config('roles')), 'idle' => (int) DB::table('settings')->where('key', 'session_idle_minutes')->value('value')]);
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        Gate::authorize('roles.attribuer');
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:180|unique:users', 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()], 'role' => ['required', Rule::in(array_keys(config('roles')))]]);
        DB::transaction(function () use ($data): void {
            $u = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $u->assignRole($data['role']);
            app(Audit::class)->append('compte.cree', auth()->id(), ['id' => $u->id, 'role' => $data['role']]);
        });

        return back();
    }

    public function update(Request $r, User $user): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        Gate::authorize('roles.attribuer');
        $data = $r->validate(['active' => 'required|boolean', 'role' => ['required', Rule::in(array_keys(config('roles')))]]);
        abort_if($user->id === $r->user()->id && (! $data['active'] || $data['role'] !== 'administrateur'), 422, 'Vous ne pouvez pas retirer votre propre accès administrateur.');
        DB::transaction(function () use ($user, $data): void {
            if ($user->active !== $data['active']) {
                $user->update(['active' => $data['active']]);
                app(Audit::class)->append($data['active'] ? 'compte.active' : 'compte.desactive', auth()->id(), ['id' => $user->id]);
            }
            $old = $user->getRoleNames()->first();
            if ($old !== $data['role']) {
                $user->syncRoles([$data['role']]);
                app(Audit::class)->append('role.change', auth()->id(), ['id' => $user->id, 'avant' => $old, 'apres' => $data['role']]);
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        return back();
    }

    public function inactivity(Request $r): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        $data = $r->validate(['minutes' => 'required|integer|min:1|max:120']);
        DB::transaction(function () use ($data): void {
            DB::table('settings')->where('key', 'session_idle_minutes')->update(['value' => (string) $data['minutes']]);
            app(Audit::class)->append('session.duree_modifiee', auth()->id(), ['minutes' => $data['minutes']]);
        });

        return back();
    }

    public function password(Request $r, User $user): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        $data = $r->validate(['password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        DB::transaction(function () use ($data, $user): void {
            $user->update(['password' => $data['password'], 'remember_token' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            app(Audit::class)->append('mot_de_passe.reinitialise', auth()->id(), ['id' => $user->id]);
        });

        return back();
    }
}
