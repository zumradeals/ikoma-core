<?php

use App\Models\User;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif habilitations du site', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->post('/administration/site/zones', ['name' => 'Hall fictif du site']);
    $this->zone = (string) DB::table('zones')->where('name', 'Hall fictif du site')->value('id');
    $this->holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive Site '.Str::random(6), 'institutional_category' => 'AILCT-01'], $this->admin);
});

function sePeriod(): array
{
    return ['valid_from' => now()->subDay()->toIso8601String(), 'valid_until' => now()->addMonth()->toIso8601String()];
}

it('accorde une habilitation de site sans événement, tracée, puis la retire avec motif et historique', function () {
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod())->assertSessionHasNoErrors();
    $row = DB::table('holder_site_entitlements')->where('holder_id', $this->holder)->first();
    expect($row->zone_id)->toBe($this->zone)->and($row->revoked_at)->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'habilitation_site.accordee')->count())->toBe(1);

    $this->get('/administration/habilitations')->assertOk()->assertInertia(fn ($p) => $p->where('site.zones', fn ($zones) => collect($zones)->pluck('name')->contains('Hall fictif du site'))->has('site.entitlements', 1)->where('site.entitlements.0.zone', 'Hall fictif du site'));

    $this->post('/administration/habilitations/site/'.$row->id.'/retirer', ['reason' => 'Fin de mission fictive'])->assertSessionHasNoErrors();
    expect(DB::table('holder_site_entitlements')->where('id', $row->id)->value('revoked_at'))->not->toBeNull();
    $this->post('/administration/habilitations/site/'.$row->id.'/retirer', ['reason' => 'Encore'])->assertSessionHasErrors('reason');
    expect(DB::table('holder_site_entitlements')->count())->toBe(1);
});

it('refuse un chevauchement, une période inversée et une personne clôturée', function () {
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod())->assertSessionHasNoErrors();
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod())->assertSessionHasErrors('valid_from');

    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone, 'valid_from' => now()->addDay()->toIso8601String(), 'valid_until' => now()->toIso8601String()])->assertSessionHasErrors('valid_until');

    app(PermanentHolders::class)->close($this->holder, 'Départ fictif', $this->admin);
    $other = (string) DB::table('zones')->whereNull('event_id')->value('id');
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $other] + ['valid_from' => now()->addYear()->toIso8601String(), 'valid_until' => now()->addYears(2)->toIso8601String()])->assertSessionHasErrors('holder_id');
});

it('protège en base : une habilitation du site ne se modifie pas, ne se supprime pas et ne vise qu’une zone du site', function () {
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod());
    $id = (string) DB::table('holder_site_entitlements')->value('id');
    expect(fn () => DB::transaction(fn () => DB::table('holder_site_entitlements')->where('id', $id)->update(['valid_until' => now()->addYears(5)])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('holder_site_entitlements')->where('id', $id)->delete()))->toThrow(QueryException::class);
});

it('ne mélange pas les moteurs : une habilitation du site n’apparaît pas dans celles d’une activité', function () {
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod());
    expect(DB::table('holder_entitlements')->where('holder_id', $this->holder)->count())->toBe(0);
    $event = (string) DB::table('events')->value('id');
    $this->get('/administration/habilitations?event='.$event)->assertOk()->assertInertia(fn ($p) => $p->where('entitlements', []));
});

it('associe une zone du site à un poste de contrôle, une seule fois, puis la retire', function () {
    $this->post('/administration/site/postes', ['code' => 'poste_8', 'name' => 'Poste 8 fictif', 'capabilities' => ['sortie']]);
    $checkpoint = (string) DB::table('checkpoints')->where('code', 'poste_8')->value('id');
    $this->post('/administration/site/postes/'.$checkpoint.'/zones-site', ['zone_id' => $this->zone])->assertSessionHasNoErrors();
    $this->post('/administration/site/postes/'.$checkpoint.'/zones-site', ['zone_id' => $this->zone])->assertSessionHasErrors('zone_id');
    $this->get('/administration/site')->assertOk()->assertInertia(fn ($p) => $p->where('checkpoints', fn ($list) => collect($list)->firstWhere('id', $checkpoint)['site_zones'][0]['name'] === 'Hall fictif du site'));


    $mapping = (string) DB::table('checkpoint_site_zones')->value('id');
    $this->delete('/administration/site/zones-site/'.$mapping)->assertSessionHasNoErrors();
    expect(DB::table('checkpoint_site_zones')->count())->toBe(0);
    expect(DB::table('audit_logs')->whereIn('action', ['site.zone_associee_poste', 'site.zone_dissociee_poste'])->count())->toBe(2);
});

it('réserve ces écrans aux droits prévus', function () {
    $agent = User::create(['name' => 'Agent fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $agent->assignRole('agent_accueil');
    $this->actingAs($agent);
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod())->assertForbidden();
    $this->post('/administration/site/postes/'.Str::uuid().'/zones-site', ['zone_id' => $this->zone])->assertForbidden();
});

it('la remise à zéro de démonstration vide aussi les habilitations et rattachements du site', function () {
    $this->post('/administration/habilitations/site', ['holder_id' => $this->holder, 'zone_id' => $this->zone] + sePeriod());
    $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
    expect($definition)->toContain('public.holder_site_entitlements, public.checkpoint_site_zones');
});
