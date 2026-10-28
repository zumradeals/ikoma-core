<?php

namespace App\Http\Controllers;

use App\Services\Pilotage;
use App\Services\VisitorApprovals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Écran de l'officier de sécurité : approuver ou refuser les invités importés ou ajoutés par les hôtes (audit global A3). */
final class ListApprovalsController
{
    public function __construct(private readonly VisitorApprovals $approvals, private readonly Pilotage $pilotage) {}

    public function index(Request $r): Response
    {
        Gate::authorize('listes.approuver');
        $event = $this->pilotage->event($r->query('event') ? (string) $r->query('event') : null);
        $batches = $event ? $this->approvals->batches($event->id) : [];
        $selected = (string) ($r->query('lot') ?: ($batches[0]['key'] ?? ''));

        return Inertia::render('Autorite/Listes', [
            'event' => $event ? ['id' => $event->id, 'name' => $event->name] : null,
            'batches' => $batches,
            'selected' => $selected,
            'visitors' => $event && $selected !== '' ? $this->approvals->visitors($event->id, $selected) : [],
        ]);
    }

    public function decide(Request $r, string $batch, string $decision): RedirectResponse
    {
        Gate::authorize('listes.approuver');
        $data = $r->validate(['event' => 'required|uuid|exists:events,id', 'ids' => 'nullable|array|max:500', 'ids.*' => 'uuid']);
        abort_unless($batch === 'hotes' || Str::isUuid($batch), 404);
        $count = $this->approvals->decide($data['event'], $batch, isset($data['ids']) ? array_values($data['ids']) : null, $decision === 'approuver', $r->user());

        return back()->with('status', $count.' invité'.($count > 1 ? 's' : '').($decision === 'approuver' ? ' approuvé' : ' refusé').($count > 1 ? 's' : '').'.');
    }
}
