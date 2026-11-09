<?php

namespace App\Http\Controllers;

use App\Services\OperationsOverview;
use App\Services\Pilotage;
use App\Services\SupervisionCentre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Opérations : vue d'ensemble opérationnelle, en LECTURE SEULE. Elle compose les services existants (Pilotage, SupervisionCentre) et la vue globale du site
 * (OperationsOverview) ; elle ne porte aucune décision de contrôle, aucune clôture ni aucun moteur. Les actions renvoient vers les écrans propriétaires (Pilotage, Postes, Journal).
 */
final class OperationsController
{
    public function __construct(private readonly Pilotage $pilotage, private readonly SupervisionCentre $centre, private readonly OperationsOverview $overview) {}

    public function index(Request $request): Response
    {
        Gate::authorize('pilotage.consulter');
        $user = $request->user();
        $event = $this->pilotage->event($request->query('event') ? (string) $request->query('event') : null);
        $names = $user->can('presences.consulter');

        return Inertia::render('Admin/Operations', [
            'events' => DB::table('events')->orderByDesc('is_permanent')->orderByDesc('starts_at')->get(['id', 'name', 'is_permanent']),
            'event' => $event ? ['id' => $event->id, 'name' => $event->name, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'status' => $event->status] : null,
            'general' => fn (): array => $this->overview->general(),
            'counters' => fn (): ?array => $event ? $this->pilotage->counters($event->id) : null,
            'overview' => fn (): ?array => $event ? $this->pilotage->overview($event->id) : null,
            // Le centre contient des noms de personnes : seulement pour qui consulte les présences (même règle que le Pilotage).
            'centre' => fn (): ?array => $event && $names ? $this->centre->build($event->id, $user) : null,
            'can' => ['presences' => $names, 'alertes' => $user->can('alertes.consulter'), 'litiges' => $user->can('litiges.decider'), 'journal' => $user->can('journal.consulter'),
                'postes' => $user->can('postes.gerer'), 'groupes' => $user->can('groupes.gerer')],
        ]);
    }
}
