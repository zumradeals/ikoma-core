<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif zones du site', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
});

it('crée une zone du site sans événement, rattachée au site, et la liste dans « Site et accès »', function () {
    $this->post('/administration/site/zones', ['name' => 'Hall principal fictif'])->assertSessionHasNoErrors();
    $zone = DB::table('zones')->where('name', 'Hall principal fictif')->first();
    expect($zone->event_id)->toBeNull()->and($zone->site_id)->toBe(DB::table('sites')->orderBy('created_at')->value('id'));
    $this->get('/administration/site')->assertOk()->assertInertia(fn ($page) => $page->where('site_zones', fn ($zones) => collect($zones)->pluck('name')->contains('Hall principal fictif')));
    expect(DB::table('audit_logs')->where('action', 'site.zone_creee')->count())->toBe(1);
});

it('refuse deux zones du site de même nom (casse ignorée)', function () {
    $before = DB::table('zones')->whereNull('event_id')->count();
    $this->post('/administration/site/zones', ['name' => 'Cour fictive'])->assertSessionHasNoErrors();
    $this->post('/administration/site/zones', ['name' => 'COUR fictive'])->assertSessionHasErrors('name');
    expect(DB::table('zones')->whereNull('event_id')->count())->toBe($before + 1);
});

it('renomme une zone du site, jamais une zone d’activité', function () {
    $this->post('/administration/site/zones', ['name' => 'Parking fictif']);
    $id = (string) DB::table('zones')->where('name', 'Parking fictif')->value('id');
    $this->put('/administration/site/zones/'.$id, ['name' => 'Parking visiteurs fictif'])->assertSessionHasNoErrors();
    expect(DB::table('zones')->where('id', $id)->value('name'))->toBe('Parking visiteurs fictif');
    $activityZone = (string) Str::uuid(); // ancienne zone propre à une activité (ancien chemin, avant son retrait)
    DB::table('zones')->insert(['id' => $activityZone, 'event_id' => DemoSeeder::id('event'), 'name' => 'Zone propre fictive', 'created_at' => now(), 'updated_at' => now()]);
    $this->put('/administration/site/zones/'.$activityZone, ['name' => 'Autre nom'])->assertNotFound();
});

it('réserve la gestion des zones du site à la permission « postes.gerer »', function () {
    $agent = User::create(['name' => 'Agent fictif zones', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $agent->assignRole('agent_accueil');
    $this->actingAs($agent)->post('/administration/site/zones', ['name' => 'Zone interdite'])->assertForbidden();
    expect(DB::table('zones')->where('name', 'Zone interdite')->exists())->toBeFalse();
});

it('laisse les zones des activités inchangées : une zone du site n’apparaît dans aucune liste d’activité', function () {
    $this->post('/administration/site/zones', ['name' => 'Zone du site fictive']);
    $event = DemoSeeder::id('event');
    expect(DB::table('zones')->where('event_id', $event)->where('name', 'Zone du site fictive')->exists())->toBeFalse();
});
