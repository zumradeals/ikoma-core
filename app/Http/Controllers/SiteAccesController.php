<?php

namespace App\Http\Controllers;

use App\Services\Poste\PostCapabilities;
use App\Services\SiteConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Site et accès » (V1) : le site, ses postes de contrôle permanents, leurs capacités, les tablettes affectées et les zones gardées, à partir des données réelles.
 * Aucun plan géographique. Droit : `postes.gerer` (administrateur). La correspondance poste–zone se règle par les routes existantes de l'écran des accès.
 */
final class SiteAccesController
{
    public function index(SiteConfiguration $site): Response
    {
        Gate::authorize('postes.gerer');

        return Inertia::render('Admin/SiteAcces', $site->overview());
    }

    public function rename(Request $request, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $site->renameSite((string) $request->validate(['name' => 'required|string|max:160'])['name'], $request->user());

        return back()->with('status', 'Nom du site enregistré.');
    }

    public function createZone(Request $request, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $site->createSiteZone((string) $request->validate(['name' => 'required|string|max:120'])['name'], $request->user());

        return back()->with('status', 'Zone du site créée.');
    }

    public function renameZone(Request $request, string $zone, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $site->renameSiteZone($zone, (string) $request->validate(['name' => 'required|string|max:120'])['name'], $request->user());

        return back()->with('status', 'Zone du site renommée.');
    }

    public function createCheckpoint(Request $request, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $request->validate($this->rules(true));
        $site->createCheckpoint($data, $request->user());

        return back()->with('status', 'Poste créé.');
    }

    public function updateCheckpoint(Request $request, string $checkpoint, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $request->validate($this->rules(false) + ['version' => 'required|integer|min:1']);
        $site->updateCheckpoint($checkpoint, $data, (int) $data['version'], $request->user());

        return back()->with('status', 'Configuration du poste enregistrée. Les tablettes concernées la reçoivent à leur prochaine synchronisation.');
    }

    public function activation(Request $request, string $checkpoint, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $request->validate(['active' => 'required|boolean', 'version' => 'required|integer|min:1']);
        $site->setActive($checkpoint, (bool) $data['active'], (int) $data['version'], $request->user());

        return back()->with('status', $data['active'] ? 'Poste réactivé.' : 'Poste désactivé : il n’a plus aucune capacité.');
    }

    public function assign(Request $request, string $device, SiteConfiguration $site): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $request->validate(['checkpoint_id' => 'nullable|uuid', 'reason' => 'nullable|string|max:200']);
        $site->assign($device, $data['checkpoint_id'] ?? null, $data['reason'] ?? null, $request->user());

        return back()->with('status', 'Affectation enregistrée. La tablette la reçoit à sa prochaine synchronisation.');
    }

    /** @return array<string, mixed> */
    private function rules(bool $withCode): array
    {
        return ($withCode ? ['code' => 'required|string|max:20'] : []) + [
            'name' => 'required|string|max:120', 'function_label' => 'nullable|string|max:160',
            'capabilities' => 'nullable|array', 'capabilities.*' => ['string', Rule::in(array_keys(PostCapabilities::CATALOGUE))], 'direction_required' => 'nullable|boolean',
        ];
    }
}
