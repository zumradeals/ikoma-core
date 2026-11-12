<?php

use App\Models\User;
use App\Services\ActivityZones;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

/** Zones du site, étape Z4 : la zone d'accueil est une propriété d'une zone du site, réglée dans « Site et accès ». */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif accueil', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
});

it('crée une zone d’accueil du site et refuse de retirer la dernière', function () {
    DB::table('zones')->update(['est_accueil' => false]);
    $name = 'Accueil fictif '.Str::uuid();
    $this->actingAs($this->admin)->post('/administration/site/zones', ['name' => $name, 'est_accueil' => true])->assertRedirect();
    $zone = DB::table('zones')->where('name', $name)->first();
    expect((bool) $zone->est_accueil)->toBeTrue();

    $this->actingAs($this->admin)->put('/administration/site/zones/'.$zone->id.'/accueil', ['est_accueil' => false])->assertSessionHasErrors('est_accueil');
    expect((bool) DB::table('zones')->where('id', $zone->id)->value('est_accueil'))->toBeTrue();

    $other = 'Autre accueil '.Str::uuid();
    $this->actingAs($this->admin)->post('/administration/site/zones', ['name' => $other])->assertRedirect();
    $otherId = DB::table('zones')->where('name', $other)->value('id');
    $this->actingAs($this->admin)->put('/administration/site/zones/'.$otherId.'/accueil', ['est_accueil' => true])->assertRedirect();
    $this->actingAs($this->admin)->put('/administration/site/zones/'.$zone->id.'/accueil', ['est_accueil' => false])->assertRedirect();
    expect((bool) DB::table('zones')->where('id', $zone->id)->value('est_accueil'))->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'site.zone_accueil')->exists())->toBeTrue();
});

it('garantit une zone d’accueil du site sans en créer de seconde, et l’ancien écran Zones redirige vers Site et accès', function () {
    DB::table('zones')->update(['est_accueil' => false]);
    ActivityZones::ensureSiteReception();
    $count = DB::table('zones')->where('est_accueil', true)->count();
    expect($count)->toBe(1);
    ActivityZones::ensureSiteReception();
    expect(DB::table('zones')->where('est_accueil', true)->count())->toBe(1);
    $this->actingAs($this->admin)->get('/administration/zones')->assertRedirect('/administration/site');
});
