<?php

namespace App\Http\Controllers;

use App\Services\Vehicles;
use App\Services\Visits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Annonce d'une visite et véhicules rattachés à une participation. Les droits (administrateur : toute participation ; hôte : ses invités) sont vérifiés par les services,
 * en plus des permissions portées par les routes.
 */
final class VisitsController
{
    public function __construct(private readonly Visits $visits, private readonly Vehicles $vehicles) {}

    public function announce(Request $r, string $visitor): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['expected_at' => 'required|date']);
        $this->visits->announce($r->user(), $visitor, $data['expected_at']);

        return $this->done($r);
    }

    public function addVehicle(Request $r, string $visitor): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['plate' => 'required|string|max:20', 'kind' => 'required|string|max:20']);
        $id = $this->vehicles->register($r->user(), $visitor, $data['plate'], $data['kind']);

        return $r->expectsJson() && ! $r->header('X-Inertia') ? response()->json(['ok' => true, 'id' => $id]) : back();
    }

    public function cancelVehicle(Request $r, string $vehicle): RedirectResponse|JsonResponse
    {
        $this->vehicles->cancel($r->user(), $vehicle);

        return $this->done($r);
    }

    private function done(Request $r): RedirectResponse|JsonResponse
    {
        return $r->expectsJson() && ! $r->header('X-Inertia') ? response()->json(['ok' => true]) : back();
    }
}
