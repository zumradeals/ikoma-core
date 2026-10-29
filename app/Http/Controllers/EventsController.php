<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Zone;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestion des événements depuis l'administration.
 * L'installation L12 reste à usage unique et ne sert jamais à ajouter un événement.
 */
final class EventsController
{
    public function index(): Response
    {
        Gate::authorize('evenements.gerer');

        return Inertia::render('Admin/Evenements', [
            'events' => Event::query()->orderByDesc('starts_at')
                ->get(['id', 'name', 'site', 'starts_at', 'ends_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('evenements.gerer');

        $data = $request->validate([
            'name' => 'bail|required|string|max:160',
            'site' => 'nullable|string|max:255',
            'starts_at' => 'bail|required|date',
            'ends_at' => 'bail|required|date|after:starts_at',
        ]);

        $event = DB::transaction(function () use ($data): Event {
            $event = Event::create([
                'name' => trim($data['name']),
                'site' => isset($data['site']) && trim($data['site']) !== '' ? trim($data['site']) : null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
            ]);
            // L'accueil existe immédiatement, comme dans le parcours d'installation L12.
            Zone::create(['event_id' => $event->id, 'name' => 'Accueil', 'est_accueil' => true]);
            app(Audit::class)->append('evenement.cree', auth()->id(), [
                'id' => $event->id,
                'fields' => ['name', 'site', 'starts_at', 'ends_at'],
            ]);

            return $event;
        });

        $request->session()->put('admin_reference_event', $event->id);

        return redirect('/administration?event='.$event->id)
            ->with('status', 'Événement créé. Vous pouvez maintenant configurer les zones, les catégories, les badges et les postes.');
    }
}
