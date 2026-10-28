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

it('escalade une seule fois les alertes critiques ou hautes restées sans prise en compte, et seulement celles-là', function () {
    $old = now()->subMinutes(10);
    $mk = function (string $type, string $severity, array $extra = []) use ($old): string {
        $id = (string) Str::uuid();
        DB::table('alerts')->insert(['id' => $id, 'event_id' => $this->event, 'type' => $type, 'severity' => $severity, 'message' => 'Alerte fictive', 'created_at' => $old, 'updated_at' => $old] + $extra);

        return $id;
    };
    $critical = $mk('controle_refuse', 'critique');
    $high = $mk('refus', 'haute');
    $normal = $mk('hors_periode', 'normale');
    $acked = $mk('refus', 'haute', ['acknowledged_by' => $this->supervisor->id, 'acknowledged_at' => now()]);
    $recent = (string) Str::uuid();
    DB::table('alerts')->insert(['id' => $recent, 'event_id' => $this->event, 'type' => 'refus', 'severity' => 'critique', 'message' => 'Alerte récente', 'created_at' => now()->subMinute(), 'updated_at' => now()]);
    $auditStart = (int) DB::table('audit_logs')->max('id');

    expect(app(Alerts::class)->escalate())->toBe(2);
    $escalated = DB::table('alerts')->whereNotNull('escalated_at')->pluck('id')->all();
    expect($escalated)->toContain($critical)->toContain($high)->not->toContain($normal)->not->toContain($acked)->not->toContain($recent);
    expect(app(Alerts::class)->escalate())->toBe(0); // une seule fois
    $logs = DB::table('audit_logs')->where('id', '>', $auditStart)->where('action', 'alerte.escaladee')->get();
    expect($logs)->toHaveCount(2)->and($logs->every(fn ($l) => ! str_contains((string) $l->details, 'Alerte fictive')))->toBeTrue();

    // le délai est un réglage : à 30 minutes rien n'est escaladé
    DB::table('alerts')->update(['escalated_at' => null]);
    DB::table('settings')->where('key', 'alerte_escalade_minutes')->update(['value' => '30']);
    expect(app(Alerts::class)->escalate())->toBe(0);
    $this->artisan('alertes:escalader')->expectsOutputToContain('0 alerte(s) escaladée(s).')->assertExitCode(0);
});

it('montre l’escalade à l’administrateur et au superviseur, et compte les alertes critiques ouvertes', function () {
    $id = (string) Str::uuid();
    DB::table('alerts')->insert(['id' => $id, 'event_id' => $this->event, 'type' => 'controle_refuse', 'severity' => 'critique', 'message' => 'Badge B-014 annulé présenté au point « Porte nord ».', 'created_at' => now()->subMinutes(9), 'updated_at' => now(), 'escalated_at' => now()]);
    expect(app(Pilotage::class)->criticalAlertCount($this->event))->toBe(1)->and(app(Pilotage::class)->overview($this->event)['escaladees'])->toBe(1);
    $row = collect(app(Pilotage::class)->alerts($this->event, 'a_traiter'))->firstWhere('id', $id);
    expect($row['escalated_at'])->not->toBeNull();
    $this->actingAs($this->supervisor)->get('/pilotage')->assertInertia(fn (Assert $p) => $p->where('criticalAlerts', 1));

    $admin = User::create(['name' => 'Administrateur fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $admin->assignRole('administrateur');
    $admin->update(['two_factor_confirmed_at' => now()]);
    $this->actingAs($admin)->get('/administration')->assertInertia(function (Assert $p) {
        $watch = collect($p->toArray()['props']['watch']);
        expect($watch->first()['title'])->toBe('1 alerte sans prise en compte')->and($watch->first()['text'])->toContain('Appelez-le');
    });
    // une fois prise en compte, l'alerte disparaît du tableau de l'administrateur
    DB::table('alerts')->where('id', $id)->update(['acknowledged_by' => $this->supervisor->id, 'acknowledged_at' => now()]);
    $this->actingAs($admin)->get('/administration')->assertInertia(fn (Assert $p) => expect(collect($p->toArray()['props']['watch'])->pluck('title')->all())->not->toContain('1 alerte sans prise en compte'));
});
