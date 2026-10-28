<?php

namespace App\Http\Controllers;

use App\Services\Approvals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Validations demandées à un second administrateur (audit global D2). Droit réservé à l'administrateur ; l'auteur d'une demande ne peut pas la valider. */
final class ApprovalsController
{
    public function __construct(private readonly Approvals $approvals) {}

    public function index(Request $r): Response
    {
        Gate::authorize('approbations.decider');
        $me = $r->user()->id;

        return Inertia::render('Admin/Approbations', [
            'pending' => $this->approvals->pending()->map(fn (\stdClass $a) => ['id' => $a->id, 'summary' => $a->summary, 'requester' => $a->requester, 'created_at' => $a->created_at, 'expires_at' => $a->expires_at, 'mine' => $a->requested_by === $me])->values(),
            'soleAdministrator' => $this->approvals->soleAdministrator(),
        ]);
    }

    public function approve(Request $r, string $approval): RedirectResponse
    {
        Gate::authorize('approbations.decider');
        $this->approvals->decide($approval, $r->user(), true);

        return back()->with('status', 'Demande validée et exécutée.');
    }

    public function refuse(Request $r, string $approval): RedirectResponse
    {
        Gate::authorize('approbations.decider');
        $this->approvals->decide($approval, $r->user(), false);

        return back()->with('status', 'Demande refusée.');
    }
}
