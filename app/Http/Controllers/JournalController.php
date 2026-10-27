<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JournalController
{
    public function __invoke(Request $r): Response
    {
        Gate::authorize('journal.consulter');
        $filters = $r->validate(['actor' => 'nullable|uuid', 'action' => 'nullable|string|max:80', 'date' => 'nullable|date_format:Y-m-d']);
        $q = DB::table('audit_logs');
        if (! empty($filters['actor'])) {
            $q->where('actor_id', $filters['actor']);
        }
        if (! empty($filters['action'])) {
            $q->where('action', $filters['action']);
        }
        if (! empty($filters['date'])) {
            $q->where('occurred_at', 'like', $filters['date'].'%');
        }

        return Inertia::render('Admin/Journal', ['entries' => $q->orderByDesc('id')->paginate(30)->withQueryString(), 'actors' => DB::table('users')->select('id', 'name')->orderBy('name')->get(), 'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action'), 'filters' => $filters]);
    }
}
