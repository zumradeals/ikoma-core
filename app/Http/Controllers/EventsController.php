<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Zone;
use App\Services\Audit;
use App\Services\DataLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestion des événements depuis l'administration.
 * L'installation L12 reste à usage unique et ne sert jamais à ajouter un événement.
 */
final class EventsController
{
    public function index(Request $request): Response
    {
        Gate::authorize('evenements.gerer');

        return Inertia::render('Admin/Evenements', [
            'archives' => $request->boolean('archives'),
            'events' => Event::query()->when($request->boolean('archives'), fn ($q) => $q->where('status', 'archive'), fn ($q) => $q->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'archive')))->orderByDesc('starts_at')
                ->get(['id', 'name', 'site', 'starts_at', 'ends_at', 'status']),
        ]);
    }

    public function update(Request $request, string $event): RedirectResponse
    {
        Gate::authorize('evenements.gerer');
        $data = $request->validate(['name' => 'required|string|max:160', 'site' => 'nullable|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']);
        DB::transaction(function () use ($event, $data): void {
            app(DataLifecycle::class)->lock();
            if (in_array(DB::table('events')->where('id', $event)->value('status'), ['clos', 'archive'], true)) {
                throw ValidationException::withMessages(['name' => 'Cet événement est clôturé ou archivé.']);
            }
            foreach (['access_events', 'badge_assignments', 'import_batches', 'sync_conflicts'] as $table) {
                if (DB::table($table)->where('event_id', $event)->exists()) {
                    throw ValidationException::withMessages(['name' => 'Cet événement possède une activité : modification de préparation interdite.']);
                }
            }
            if (DB::table('devices')->where('event_id', $event)->whereNotNull('enrolled_at')->exists()) {
                throw ValidationException::withMessages(['name' => 'Une tablette a déjà été enrôlée.']);
            }
            if (DB::table('identity_checks')->whereIn('visitor_id', DB::table('visitors')->where('event_id', $event)->select('id'))->exists()) {
                throw ValidationException::withMessages(['name' => 'Des contrôles existent.']);
            }
            DB::table('events')->where('id', $event)->update([...$data, 'updated_at' => now(), 'list_revision' => DB::raw('list_revision + 1')]);
            app(Audit::class)->append('gestion.evenement_modifie', auth()->id(), ['event_id' => $event, 'fields' => array_keys($data)]);
        });

        return back()->with('status', 'Événement modifié.');
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
