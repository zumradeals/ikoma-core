<?php

use App\Models\User;
use App\Services\Alerts;
use App\Services\Pilotage;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->event = DemoSeeder::id('event');
    $this->supervisor = User::create(['name' => 'Superviseur fictif vue', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $this->supervisor->assignRole('superviseur');
    $this->supervisor->update(['two_factor_confirmed_at' => now()]);
    // Les données laissées par d'autres exécutions de la base de test ne comptent pas.
    DB::table('alerts')->where('event_id', $this->event)->delete();
    DB::table('refusals')->delete();
});

it('compte les alertes par gravité, les cas à décider, les cars et les tablettes sans nouvelles', function () {
    $o = app(Pilotage::class)->overview($this->event);
    expect($o['critiques'])->toBe(0)->and($o['hautes'])->toBe(0)->and($o['litiges'])->toBe(0);

    $alerts = app(Alerts::class);
    $alerts->raise($this->event, 'refus', 'haute', 'visiteur', DemoSeeder::id('visitor1'), 'Accès refusé', 'refus:vue-1');
    $alerts->raise($this->event, 'conflit_sync', 'critique', 'conflit', (string) Str::uuid(), 'Conflit', 'conflit:vue-1');
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => DemoSeeder::id('visitor1'), 'reason' => 'Motif fictif', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', 'x'), 'revoked_at' => null, 'last_synced_at' => now()->subHours(2)]);

    $o = app(Pilotage::class)->overview($this->event);
    expect($o['critiques'])->toBe(1)->and($o['hautes'])->toBe(1)->and($o['litiges'])->toBe(1)->and($o['postes_silencieux'])->toBeGreaterThanOrEqual(1);
    expect($o['cars'])->toHaveKeys(['total', 'partis', 'arrives', 'ecarts']);

    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['last_synced_at' => now()]);
    expect(app(Pilotage::class)->overview($this->event)['postes_silencieux'])->toBe($o['postes_silencieux'] - 1);
});

it('expose la vue d’ensemble en rechargement partiel, sans nom de personne', function () {
    $this->actingAs($this->supervisor)->get('/pilotage')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Pilotage/Index')
        ->has('overview', fn (Assert $o) => $o->hasAll(['critiques', 'hautes', 'litiges', 'cars', 'postes_silencieux'])->etc()));
    $json = json_encode(app(Pilotage::class)->overview($this->event));
    foreach (DB::table('visitors')->where('event_id', $this->event)->limit(20)->pluck('full_name') as $name) {
        expect($json)->not->toContain((string) $name);
    }
});
