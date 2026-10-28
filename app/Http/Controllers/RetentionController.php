<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\DataRetention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RetentionController
{
    public function index(DataRetention $retention): Response
    {
        Gate::authorize('comptes.gerer');

        return Inertia::render('Admin/Retention', ['durations' => $retention->settings(), 'approved' => DB::table('settings')->where('key', 'conservation_validee')->value('value') === '1', 'exportMaximum' => (int) DB::table('settings')->where('key', 'journal_export_max_lignes')->value('value')]);
    }

    public function update(Request $r): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        $rules = ['approved' => 'required|boolean', 'exportMaximum' => 'required|integer|min:1|max:50000', 'durations' => 'required|array:'.implode(',', DataRetention::TYPES)];
        foreach (DataRetention::TYPES as $type) {
            $rules['durations.'.$type] = 'required|integer|min:1|max:3650';
        }
        $data = $r->validate($rules);
        foreach (['photos', 'notifications', 'synchronisations'] as $type) {
            if ($data['durations'][$type] > $data['durations']['visiteurs']) {
                throw ValidationException::withMessages(['durations.'.$type => 'Ce délai ne doit pas dépasser celui des visiteurs.']);
            }
        }
        if ($data['durations']['evenements'] < $data['durations']['visiteurs']) {
            throw ValidationException::withMessages(['durations.evenements' => 'Ce délai doit couvrir celui des visiteurs.']);
        }
        DB::transaction(function () use ($data, $r): void {
            foreach ($data['durations'] as $type => $days) {
                DB::table('settings')->where('key', 'conservation_'.$type.'_jours')->update(['value' => (string) $days]);
            }
            DB::table('settings')->where('key', 'conservation_validee')->update(['value' => $data['approved'] ? '1' : '0']);
            DB::table('settings')->where('key', 'journal_export_max_lignes')->update(['value' => (string) $data['exportMaximum']]);
            app(Audit::class)->append('conservation.reglee', $r->user()->id, ['jours' => $data['durations'], 'approuvee' => $data['approved'], 'maximum_export' => $data['exportMaximum']]);
        });

        return back();
    }
}
