<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BadgeSettingsController
{
    public function update(Request $r): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $data = $r->validate(['margin' => 'required|integer|min:0|max:100', 'reusable' => ['required', Rule::in(['oui', 'non'])]]);
        DB::transaction(function () use ($data): void {
            DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => (string) $data['margin']]);
            DB::table('settings')->where('key', 'badge_reutilisable')->update(['value' => $data['reusable']]);
            app(Audit::class)->append('badge.reglage_modifie', auth()->id(), ['marge_pourcent' => (int) $data['margin'], 'reutilisable' => $data['reusable']]);
        });

        return back();
    }
}
