<?php

namespace App\Http\Middleware;

use App\Services\BadgesCartesSummary;
use App\Services\DemoLab;
use App\Services\FoundingModules;
use App\Services\Institution;
use App\Services\Pilotage;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'flash' => ['status' => $request->session()->get('status')], 'institution' => fn () => app(Institution::class)->shared(), 'demoLabAvailable' => DemoLab::available(), 'f06Active' => fn () => app(FoundingModules::class)->f06Active(), 'auth' => ! in_array($request->path(), ['/', 'poste/shell', 'controle/shell', 'groupe/shell', 'pilotage/evacuation/shell'], true) && $request->user() ? ['id' => $request->user()->id, 'name' => $request->user()->name, 'isAdmin' => $request->user()->hasRole('administrateur'), 'permissions' => $request->user()->getAllPermissions()->pluck('name')] : null, 'adminContext' => fn () => $request->user()?->hasRole('administrateur') && ($request->is('administration', 'administration/*', 'securite', 'user/confirm-password'))
            ? $this->adminContext($request) : null,
            ...($this->inBadgesCartes($request) ? ['badgesCartes' => fn () => $this->badgesCartes($request)] : [])];
    }

    /**
     * Compteurs du cadre « Badges et cartes » : seulement sur les pages de cet espace et pour qui peut gérer les badges (aucune donnée personnelle).
     *
     * @return array<string, mixed>|null
     */
    private function badgesCartes(Request $request): ?array
    {
        return $request->user()?->can('badges.gerer') === true ? app(BadgesCartesSummary::class)->get() : null;
    }

    /** Pages de l'espace « Badges et cartes » : la propriété n'existe que là (aucun ajout aux pages publiques). */
    private function inBadgesCartes(Request $request): bool
    {
        return $request->isMethod('GET') && $request->is('administration/badges', 'administration/badges/*', 'administration/titulaires', 'administration/titulaires/*', 'administration/atelier', 'administration/modeles');
    }

    /**
     * Événement de référence du tableau, sans changer la portée des modules existants.
     *
     * @return array{event: array{id: string, name: string}}|null
     */
    private function adminContext(Request $request): ?array
    {
        $reference = $request->query('event', $request->session()->get('admin_reference_event'));
        $event = app(Pilotage::class)->event(is_string($reference) ? $reference : null);

        return $event ? ['event' => ['id' => $event->id, 'name' => $event->name]] : null;
    }
}
