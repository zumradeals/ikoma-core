<?php

namespace App\Http\Controllers;

use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BadgeLotsController
{
    public function store(Request $r, BadgeLots $lots): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $r->validate(['event_id' => 'bail|required|uuid|exists:events,id']);
        $data = $r->validate(['event_id' => 'required|uuid', 'category_id' => ['bail', 'required', 'uuid', Rule::exists('categories', 'id')->where('event_id', $r->input('event_id'))], 'expected' => ['required', 'integer', 'min:1', 'max:'.BadgeLots::MAX_EXPECTED]]);
        try {
            $lots->generate($data['event_id'], $data['category_id'], (int) $data['expected'], $r->user());
        } catch (BadgeRefused $e) {
            return back()->withErrors(['badge' => $e->getMessage()]);
        }

        return back();
    }
}
