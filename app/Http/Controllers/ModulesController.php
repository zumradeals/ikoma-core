<?php

namespace App\Http\Controllers;

use App\Services\FoundingModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ModulesController
{
    public function index(Request $request, FoundingModules $modules): Response
    {
        Gate::authorize('comptes.gerer');
        abort_unless($request->user()->hasRole('administrateur'), 403);

        return Inertia::render('Admin/Modules', [
            'modules' => $modules->catalog(),
        ]);
    }

    public function update(Request $request, FoundingModules $modules): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        abort_unless($request->user()->hasRole('administrateur'), 403);
        $data = $request->validate([
            'module' => ['required', Rule::in(['F06'])],
            'active' => 'required|boolean',
            'confirmation' => 'required|string|max:80',
        ]);
        $active = (bool) $data['active'];
        $expected = $active ? 'ACTIVER F06' : 'DESACTIVER F06';
        if ($data['confirmation'] !== $expected) {
            return back()->withErrors(['confirmation' => 'Saisissez exactement « '.$expected.' » pour confirmer.']);
        }
        $modules->setF06($active, $request->user());

        return back()->with('status', $active ? 'F06 activé. Les postes doivent synchroniser leurs listes.' : 'F06 désactivé. Les données et historiques sont conservés.');
    }
}
