<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\SecurityProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Choix du profil de sécurité de l'installation (audit global). Droit réservé à l'administrateur ; chaque changement est journalisé. */
final class SecurityProfileController
{
    public function show(): Response
    {
        Gate::authorize('securite.gerer');

        return Inertia::render('Admin/ProfilSecurite', ['profile' => SecurityProfile::current(), 'production' => app()->environment('production')]);
    }

    public function update(Request $r, Audit $audit): RedirectResponse
    {
        Gate::authorize('securite.gerer');
        $data = $r->validate(['profile' => ['required', Rule::in([SecurityProfile::STANDARD, SecurityProfile::STRICT])]]);
        $before = SecurityProfile::current();
        DB::transaction(function () use ($data, $before, $audit, $r): void {
            DB::table('settings')->where('key', 'profil_securite')->update(['value' => $data['profile']]);
            if ($before !== $data['profile']) {
                $audit->append('securite.profil_modifie', $r->user()->id, ['avant' => $before, 'apres' => $data['profile']]);
            }
        });

        return back();
    }
}
