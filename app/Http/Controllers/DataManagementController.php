<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\DataLifecycle;
use App\Services\DataRetention;
use App\Services\Maintenance;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class DataManagementController
{
    public function index(Request $r, DataLifecycle $lifecycle): Response
    {
        Gate::authorize('comptes.gerer');
        $input = $r->validate(['entity' => 'nullable|in:'.implode(',', array_keys(DataLifecycle::ENTITIES)), 'id' => 'required_with:entity|uuid']);
        $preview = null;
        if (isset($input['entity'], $input['id'])) {
            $preview = DB::transaction(function () use ($input, $r, $lifecycle) {
                $lifecycle->lock();

                return $lifecycle->inspect($input['entity'], $input['id'], $r->user());
            });
            $r->session()->put('data_preview', ['entity' => $input['entity'], 'id' => $input['id'], 'actor' => $r->user()->id, 'fingerprint' => $preview['fingerprint'], 'expires' => now()->addMinutes(10)->timestamp]);
        }

        return Inertia::render('Admin/DataManagement', [
            'preview' => $preview, 'maintenanceReport' => $r->session()->get('maintenance_report'),
            'events' => DB::table('events')->orderBy('name')->get(['id', 'name', 'status']),
            'counts' => ['evenements_ouverts' => DB::table('events')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['clos', 'archive']))->count(), 'evenements_clos' => DB::table('events')->where('status', 'clos')->count(), 'evenements_archives' => DB::table('events')->where('status', 'archive')->count(), 'personnes_actives' => DB::table('visitors')->whereNull('withdrawn_at')->count(), 'personnes_retirees' => DB::table('visitors')->whereNotNull('withdrawn_at')->count()],
            'badges' => DB::table('badges')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'durations' => app(DataRetention::class)->settings(),
            'approved' => DB::table('settings')->where('key', 'conservation_validee')->value('value') === '1',
            'history' => DB::table('audit_logs')->where(fn ($q) => $q->where('action', 'like', 'gestion.%')->orWhere('action', 'donnees.purgees')->orWhere('action', 'demo.donnees_purgees'))->orderByDesc('id')->limit(50)->get(['id', 'actor_id', 'occurred_at', 'action', 'details']),
            'requests' => DB::table('maintenance_requests')->orderByDesc('created_at')->limit(30)->get(['id', 'operation', 'status', 'result', 'created_at']),
            'demoCounts' => ['evenements' => DB::table('events')->where('id', DemoSeeder::id('event'))->count(), 'visiteurs' => DB::table('visitors')->where('event_id', DemoSeeder::id('event'))->count(), 'badges' => DB::table('badges')->where('event_id', DemoSeeder::id('event'))->count()],
            'pendingFiles' => DB::table('pending_file_deletions')->whereNull('completed_at')->count(),
        ]);
    }

    public function destroy(Request $r, DataLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        $data = $r->validate(['reference' => 'required|uuid', 'password' => 'required|string|max:1024']);
        $preview = $r->session()->pull('data_preview');
        try {
            if (! is_array($preview) || $preview['expires'] < now()->timestamp || $preview['actor'] !== $r->user()->id || $preview['id'] !== $data['reference']) {
                throw ValidationException::withMessages(['operation' => 'Aperçu absent, expiré ou référence incorrecte. Recommencez l’analyse.']);
            }
            $this->confirm($r->user(), $data['password']);
            $counts = $lifecycle->delete($preview['entity'], $preview['id'], $r->user(), $preview['fingerprint']);
        } catch (Throwable $e) {
            app(Audit::class)->append('gestion.suppression_refusee', $r->user()->id, ['entite' => $preview['entity'] ?? null, 'id' => $preview['id'] ?? null, 'resultat' => 'refus']);
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            throw ValidationException::withMessages(['operation' => 'Opération refusée. Une dépendance ou une erreur empêche la suppression. Aucune suppression n’a été validée.']);
        }

        return redirect('/administration/donnees')->with('status', 'Suppression effectuée : '.array_sum($counts).' élément(s). Le journal et les identifiants protégés sont conservés.');
    }

    public function transition(Request $r, string $event, string $action, DataLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('evenements.gerer');
        abort_unless(in_array($action, ['cloturer', 'archiver'], true), 404);
        $data = $r->validate(['confirmation' => 'required|accepted']);
        try {
            $lifecycle->transition($event, $action, $r->user());
        } catch (Throwable $e) {
            app(Audit::class)->append('gestion.transition_refusee', $r->user()->id, ['event_id' => $event, 'operation' => $action, 'resultat' => 'refus']);
            throw $e;
        }

        return back()->with('status', $action === 'cloturer' ? 'Événement clôturé. Ses historiques sont conservés.' : 'Événement archivé. Vous pouvez le consulter dans les archives.');
    }

    public function maintenancePreview(Request $r): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        abort_unless($r->user()->hasRole('administrateur'), 403);
        $data = $r->validate(['operation' => 'required|in:demo,conservation']);
        try {
            $report = DB::transaction(function () use ($data) {
                DB::statement('SELECT pg_advisory_xact_lock(731903)');
                app(DataLifecycle::class)->lock();

                return app(Maintenance::class)->preview($data['operation']);
            });
        } catch (\RuntimeException $e) {
            app(Audit::class)->append('gestion.analyse_refusee', $r->user()->id, ['operation' => $data['operation']]);
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
        $r->session()->put('maintenance_preview', ['operation' => $data['operation'], 'actor' => $r->user()->id, 'fingerprint' => $report['fingerprint'], 'expires' => now()->addMinutes(10)->timestamp]);

        return back()->with('maintenance_report', ['operation' => $data['operation'], ...$report]);
    }

    public function maintenanceRequest(Request $r): RedirectResponse
    {
        Gate::authorize('comptes.gerer');
        abort_unless($r->user()->hasRole('administrateur'), 403);
        $data = $r->validate(['operation' => 'required|in:demo,conservation', 'password' => 'required|string|max:1024', 'confirmation' => 'required|string|max:30']);
        $preview = $r->session()->pull('maintenance_preview');
        try {
            $this->confirm($r->user(), $data['password']);
            if (! is_array($preview) || $preview['expires'] < now()->timestamp || $preview['actor'] !== $r->user()->id || $preview['operation'] !== $data['operation'] || $data['confirmation'] !== $data['operation']) {
                throw ValidationException::withMessages(['operation' => 'Analyse expirée ou confirmation incorrecte.']);
            }
            DB::transaction(function () use ($data, $preview, $r): void {
                DB::statement('SELECT pg_advisory_xact_lock(731903)');
                app(DataLifecycle::class)->lock();
                $report = app(Maintenance::class)->preview($data['operation']);
                if (! hash_equals($report['fingerprint'], $preview['fingerprint'])) {
                    throw ValidationException::withMessages(['operation' => 'Les données ont changé. Recommencez l’analyse.']);
                }
                app(Maintenance::class)->request($data['operation'], $r->user(), $preview['fingerprint']);
            });
        } catch (Throwable $e) {
            app(Audit::class)->append('gestion.demande_refusee', $r->user()->id, ['operation' => $data['operation']]);
            if ($e instanceof ValidationException) {
                throw $e;
            }
            throw ValidationException::withMessages(['operation' => 'La demande n’a pas pu être enregistrée. Recommencez l’analyse.']);
        }

        return back()->with('status', 'Demande enregistrée. Elle reste en attente du service de maintenance et de la vérification matérielle de sauvegarde. Aucun nettoyage n’est encore déclaré réussi.');
    }

    private function confirm(User $actor, string $password): void
    {
        if (! $actor->active || $actor->two_factor_confirmed_at === null || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['password' => 'Vérification d’identité non acceptée.']);
        }
    }
}
