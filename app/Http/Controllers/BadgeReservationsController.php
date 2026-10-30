<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Group;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BadgeReservationsController
{
    public function store(Request $r, BadgeLots $lots): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $all = $r->boolean('all_available');
        $data = $r->validate([
            'batch_id' => 'required|uuid|exists:badge_batches,id',
            'all_available' => 'sometimes|boolean',
            'from' => $all ? 'nullable|integer|min:1' : 'required|integer|min:1',
            'to' => $all ? 'nullable|integer|min:1' : 'required|integer|gte:from',
            'target_type' => ['required', Rule::in($all ? ['poste'] : ['poste', 'groupe'])],
            'target_id' => ['required', 'uuid', Rule::exists($r->input('target_type') === 'groupe' ? 'groups' : 'devices', 'id')],
        ]);
        $target = $data['target_type'] === 'groupe' ? Group::findOrFail($data['target_id']) : Device::findOrFail($data['target_id']);
        try {
            $count = $all
                ? $lots->reserveAvailable($data['batch_id'], $target, $r->user())
                : $lots->reserve($data['batch_id'], (int) $data['from'], (int) $data['to'], $target, $r->user());
        } catch (BadgeRefused $e) {
            return back()->withErrors(['badge' => $e->getMessage()]);
        }

        return back()->with('status', $count.' badge(s) réservé(s) avec succès. Synchronisez le poste pour utiliser les nouveaux badges.');
    }
}
