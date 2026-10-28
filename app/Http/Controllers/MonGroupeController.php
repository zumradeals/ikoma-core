<?php

namespace App\Http\Controllers;

use App\Services\Groups;
use App\Services\Poste\DeskRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Mon groupe » : l'encadreur ne voit et ne confirme que le groupe dont il est désigné. Le contrôle est fait par objet
 * (le groupe est toujours cherché parmi ceux de l'utilisateur), jamais par le seul rôle.
 */
final class MonGroupeController
{
    public function __construct(private readonly Groups $groups) {}

    public function index(Request $r): Response
    {
        $mine = DB::table('groups')->where('supervisor_id', $r->user()->id)->orderBy('name')->get(['id', 'name', 'bus_number']);
        $wanted = (string) $r->query('groupe', '');
        $group = $mine->firstWhere('id', $wanted) ?? $mine->first();

        return Inertia::render('MonGroupe', ['groups' => $mine, 'state' => fn () => $group ? $this->groups->state($group->id) : null]);
    }

    public function confirm(Request $r, string $visitor): RedirectResponse
    {
        $group = DB::table('groups')->where('supervisor_id', $r->user()->id)->whereExists(fn ($q) => $q->selectRaw('1')->from('visitors')->where('visitors.id', $visitor)->whereColumn('visitors.group_id', 'groups.id'))->first()
            ?? throw new DeskRefused('groupe_interdit', 'Cette personne ne fait pas partie de votre groupe.', 403);
        $this->groups->confirm($r->user(), $group->id, $visitor);

        return back();
    }
}
