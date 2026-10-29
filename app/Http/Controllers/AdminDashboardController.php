<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Tableau de bord de l'administrateur : permission evenements.gerer (réservée à l'administrateur), double authentification obligatoire. */
final class AdminDashboardController
{
    public function __construct(private readonly AdminDashboard $dashboard) {}

    public function __invoke(Request $r): Response
    {
        Gate::authorize('evenements.gerer');
        $data = $r->validate(['event' => 'nullable|uuid']);

        $reference = $data['event'] ?? $r->session()->get('admin_reference_event');
        $dashboard = $this->dashboard->build(is_string($reference) ? $reference : null);
        $r->session()->put('admin_reference_event', $dashboard['event']['id'] ?? null);

        return Inertia::render('Admin/Tableau', $dashboard);
    }
}
