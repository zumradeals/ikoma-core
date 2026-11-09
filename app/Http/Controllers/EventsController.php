<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Zone;
use App\Services\ActivityRegistry;
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
        $input = $request->validate(['activite' => 'nullable|uuid', 'q' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1|max:100000']);
        $registry = app(ActivityRegistry::class);
        $user = $request->user();
        $selected = $input['activite'] ?? null;
        $canList = $user->can('listes.importer');

        return Inertia::render('Admin/Evenements', [
            'archives' => $request->boolean('archives'),
            'events' => $registry->rows($request->boolean('archives')),
            'kpis' => fn (): array => $registry->kpis(),
            'selected' => fn (): ?array => $selected === null ? null : $registry->profile($selected, $user->can('journal.consulter')),
            'participants' => fn (): ?array => $selected !== null && $canList && DB::table('events')->where('id', $selected)->exists() ? $registry->participants($selected, $input['q'] ?? null, (int) ($input['page'] ?? 1)) : null,
            'participantQuery' => (string) ($input['q'] ?? ''),
            'can' => ['participants' => $canList, 'journal' => $user->can('journal.consulter'), 'groupes' => $user->can('groupes.gerer'), 'badges' => $user->can('badges.gerer'), 'site' => $user->can('postes.gerer'), 'zones' => $user->can('zones.gerer')],
        ]);
    }

    public function update(Request $request, string $event): RedirectResponse
    {
        Gate::authorize('evenements.gerer');
        $data = $request->validate(['name' => 'required|string|max:160', 'site' => 'nullable|string|max:255', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']); // la nature d'une activité ne change plus après sa création
        DB::transaction(function () use ($event, $data): void {
            app(DataLifecycle::class)->lock();
            if (in_array(DB::table('events')->where('id', $event)->value('status'), ['clos', 'archive'], true)) {
                throw ValidationException::withMessages(['name' => 'Cet événement est clôturé ou archivé.']);
            }
            foreach (['access_events', 'badge_assignments', 'sync_conflicts'] as $table) {
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
            'kind' => 'sometimes|in:evenement,stage,visite',
            'starts_at' => 'bail|required|date',
            'ends_at' => 'bail|required|date|after:starts_at',
        ]);

        $event = DB::transaction(function () use ($data): Event {
            $event = Event::create([
                'name' => trim($data['name']),
                'site' => isset($data['site']) && trim($data['site']) !== '' ? trim($data['site']) : null,
                'kind' => $data['kind'] ?? 'evenement',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
            ]);
            // L'accueil existe immédiatement, comme dans le parcours d'installation L12.
            Zone::create(['event_id' => $event->id, 'name' => 'Accueil', 'est_accueil' => true]);
            app(Audit::class)->append('evenement.cree', auth()->id(), [
                'id' => $event->id,
                'fields' => ['name', 'site', 'kind', 'starts_at', 'ends_at'],
            ]);

            return $event;
        });

        $request->session()->put('admin_reference_event', $event->id);

        return redirect('/administration?event='.$event->id)
            ->with('status', 'Événement créé. Vous pouvez maintenant configurer les zones, les catégories, les badges et les postes.');
    }
}
