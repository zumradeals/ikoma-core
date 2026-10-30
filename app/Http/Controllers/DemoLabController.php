<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DemoLab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DemoLabController
{
    public function index(Request $request, DemoLab $lab): Response
    {
        DemoLab::authorize($request->user());

        return Inertia::render('Admin/DemoLab', [
            'scenarios' => DemoLab::SCENARIOS,
            'totals' => $lab->totals(),
            'demoEvents' => DB::table('events')
                ->where('is_demo', true)->orderByDesc('created_at')
                ->get(['id', 'name', 'demo_scenario', 'created_at']),
        ]);
    }

    public function generate(Request $request, DemoLab $lab): RedirectResponse
    {
        DemoLab::authorize($request->user());
        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(DemoLab::SCENARIOS))],
            'quantity' => ['required', 'integer', Rule::in([20, 100, 300, 1000])],
        ]);
        $lab->generate($data['scenario'], (int) $data['quantity'], $request->user());

        return redirect('/administration/demonstration')->with('status', 'Démonstration créée ! Ouvrez Événements, Liste d’invités ou Badges pour la tester.');
    }

    public function clear(Request $request, string $event, DemoLab $lab): RedirectResponse
    {
        DemoLab::authorize($request->user());
        $data = $request->validate([
            'confirmation' => ['required', 'in:SUPPRIMER DEMO'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $this->checkPassword($request->user(), $data['password']);
        $lab->clearScenario($event, $request->user());

        return redirect('/administration/demonstration')->with('status', 'Démonstration supprimée. Les autres événements et les comptes sont conservés.');
    }

    public function reset(Request $request, DemoLab $lab): RedirectResponse
    {
        DemoLab::authorize($request->user());
        $data = $request->validate([
            'confirmation' => ['required', 'in:REMETTRE A ZERO IKOMA'],
            'password' => ['required', 'string', 'max:1024'],
            'acknowledge' => ['required', 'accepted'],
            'clear_users' => ['required', 'boolean'],
        ]);
        $this->checkPassword($request->user(), $data['password']);
        $counts = $lab->clearBusiness($request->user(), (bool) $data['clear_users']);
        $request->session()->forget(['admin_reference_event', 'data_preview', 'maintenance_preview', 'maintenance_report']);

        return redirect('/administration/demonstration')->with('status',
            'Remise à zéro terminée : '.$counts['events'].' événement(s), '.$counts['visitors'].' personne(s), '
            .$counts['badges'].' badge(s) effacés. Le compte administrateur, les réglages techniques et l’audit sont conservés.');
    }

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect. Aucune donnée supprimée.']);
        }
    }
}
