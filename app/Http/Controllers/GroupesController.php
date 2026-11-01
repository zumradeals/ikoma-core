<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Groups;
use App\Services\Pilotage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Administration > Groupes : création des cars, encadreur, rattachement des stagiaires, règle de contrôle. Permission groupes.gerer. */
final class GroupesController
{
    public function __construct(private readonly Groups $groups, private readonly Pilotage $pilotage, private readonly Audit $audit) {}

    public function index(Request $r): Response
    {
        Gate::authorize('groupes.gerer');
        $event = $this->pilotage->event($r->query('event') ? (string) $r->query('event') : null);
        $q = trim((string) $r->query('q', ''));
        $groups = $event ? DB::table('groups as g')->leftJoin('users as u', 'u.id', '=', 'g.supervisor_id')->where('g.event_id', $event->id)->orderBy('g.name')->get(['g.id', 'g.name', 'g.bus_number', 'g.supervisor_id', 'u.name as supervisor_name', 'g.badge_batch_id', 'g.departed_at'])
            ->map(function ($g) {
                $members = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->where('v.group_id', $g->id)->orderByRaw('v.full_name COLLATE "C" ASC')->orderBy('v.id')->get(['v.id', 'v.full_name', 'c.name as category']);

                return (array) $g + [
                    'members' => $members,
                    'badges_reserves' => DB::table('badges')->where('reserved_group_id', $g->id)->count(),
                    'badges_libres' => DB::table('badges')->where('reserved_group_id', $g->id)->where('status', 'Disponible')->count(),
                ];
            }) : collect();

        return Inertia::render('Admin/Groupes', [
            'event' => $event ? ['id' => $event->id, 'name' => $event->name] : null, 'events' => DB::table('events')->orderByDesc('starts_at')->get(['id', 'name']),
            'groups' => $groups, 'mode' => $this->groups->mode(),
            'encadreurs' => User::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->filter(fn (User $u) => $u->hasRole('encadreur'))->values()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'search' => $q, 'candidates' => $event && $q !== '' ? DB::table('visitors')->where('event_id', $event->id)->whereNull('group_id')->whereRaw('public.ikoma_normalize(full_name) LIKE ?', ['%'.mb_strtolower(DB::selectOne('SELECT public.ikoma_normalize(?) AS v', [$q])->v).'%'])->orderByRaw('full_name COLLATE "C" ASC')->orderBy('id')->limit(20)->get(['id', 'full_name', 'organisation']) : [],
            'batches' => $event ? DB::table('badge_batches')->where('event_id', $event->id)->orderBy('created_at')->get(['id', 'name']) : [],
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('groupes.gerer');
        $data = $r->validate(['event_id' => 'required|uuid|exists:events,id', 'name' => 'required|string|max:120', 'bus_number' => 'nullable|string|max:60', 'supervisor_id' => 'nullable|uuid|exists:users,id']);
        $this->groups->create($data['event_id'], $data['name'], $data['bus_number'] ?? null, $data['supervisor_id'] ?? null, $r->user());

        return back();
    }

    public function designate(Request $r, string $group): RedirectResponse
    {
        Gate::authorize('groupes.gerer');
        $data = $r->validate(['supervisor_id' => 'nullable|uuid|exists:users,id']);
        $this->groups->designate($group, $data['supervisor_id'] ?? null, $r->user());

        return back();
    }

    public function attach(Request $r, string $group): RedirectResponse
    {
        Gate::authorize('groupes.gerer');
        $data = $r->validate(['visitor_id' => 'required|uuid']);
        $this->groups->attach($group, $data['visitor_id'], $r->user());

        return back();
    }

    public function detach(Request $r, string $group, string $visitor): RedirectResponse
    {
        Gate::authorize('groupes.gerer');
        $this->groups->detach($group, $visitor, $r->user());

        return back();
    }

    public function mode(Request $r): RedirectResponse
    {
        Gate::authorize('groupes.gerer');
        $data = $r->validate(['mode' => ['required', Rule::in(Groups::MODES)]]);
        DB::transaction(function () use ($data, $r): void {
            DB::table('settings')->where('key', 'controle_stagiaires')->update(['value' => $data['mode']]);
            $this->audit->append('groupe.reglage_modifie', $r->user()->id, ['controle_stagiaires' => $data['mode']]);
        });

        return back();
    }
}
