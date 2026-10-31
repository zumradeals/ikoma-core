<?php

namespace App\Http\Middleware;

use App\Services\FoundingModules;
use App\Services\Pilotage;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'flash' => ['status' => $request->session()->get('status')], 'demoLabAvailable' => app()->environment('demo', 'local', 'testing'), 'f06Active' => fn () => app(FoundingModules::class)->f06Active(), 'auth' => ! in_array($request->path(), ['/', 'poste/shell', 'controle/shell', 'groupe/shell', 'pilotage/evacuation/shell'], true) && $request->user() ? ['id' => $request->user()->id, 'name' => $request->user()->name, 'isAdmin' => $request->user()->hasRole('administrateur'), 'permissions' => $request->user()->getAllPermissions()->pluck('name')] : null, 'adminContext' => fn () => $request->user()?->hasRole('administrateur') && ($request->is('administration', 'administration/*', 'securite', 'user/confirm-password'))
            ? $this->adminContext($request) : null];
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
