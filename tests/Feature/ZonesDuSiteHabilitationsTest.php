<?php

use App\Models\User;
use App\Services\ActivityZones;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(DatabaseTransactions::class);

const ZH_TOKEN = '7777777777777777777777777777777777777777777777777777777777777777';

/** Zones du site réutilisées par les activités, étape Z2 : habilitations, politiques et contrôle d'une activité qui vise une zone du site. */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif zones', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->agent = User::create(['name' => 'Agent fictif zones', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->agent->assignRole('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $this->event, 'name' => 'Activité fictive zones', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'created_at' => now(), 'updated_at' => now()]);
    $this->siteZone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $this->siteZone, 'event_id' => null, 'name' => 'Zone commune fictive', 'est_accueil' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event, 'name' => 'Poste fictif', 'station' => 'Entrée', 'point_code' => 'poste_2', 'token_hash' => hash('sha256', ZH_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => 'AILCT-01'], $this->admin);
    app(HolderCards::class)->issue($this->holder, $this->admin);
    $this->qr = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $this->holder)->where('k.status', 'active')->value('b.qr_token');
    $this->policy = fn (bool $permanent, bool $offline = false) => DB::table('site_zone_policies')->upsert([['zone_id' => $this->siteZone, 'classification' => 'NEUTRE-TEST', 'permanent_allowed' => $permanent, 'offline_entry_allowed' => $offline, 'created_at' => now(), 'updated_at' => now()]], ['zone_id'], ['permanent_allowed', 'offline_entry_allowed']);
    $this->grant = fn () => app(HolderEntitlements::class)->grant($this->holder, $this->event, $this->siteZone, now()->subHour()->toIso8601String(), now()->addHours(8)->toIso8601String(), $this->admin);
    $this->control = fn (?array $point = null) => $this->actingAs($this->agent)->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$this->qr, 'point' => $point], ['X-Device-Token' => ZH_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json']);
});

it('offre à une activité ses zones et celles du site, jamais celles d’une autre activité', function () {
    $other = (string) Str::uuid();
    DB::table('events')->insert(['id' => $other, 'name' => 'Autre activité fictive', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $foreign = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $foreign, 'event_id' => $other, 'name' => 'Zone étrangère', 'created_at' => now(), 'updated_at' => now()]);
    expect(ActivityZones::allows($this->event, $this->siteZone))->toBeTrue()->and(ActivityZones::allows($this->event, $foreign))->toBeFalse()->and(ActivityZones::allows($other, $foreign))->toBeTrue();
});

it('accorde à un titulaire une habilitation d’activité sur une zone du site, et refuse une zone d’une autre activité', function () {
    ($this->grant)();
    expect(DB::table('holder_entitlements')->where('holder_id', $this->holder)->where('zone_id', $this->siteZone)->where('event_id', $this->event)->exists())->toBeTrue();
    $other = (string) Str::uuid();
    DB::table('events')->insert(['id' => $other, 'name' => 'Autre activité fictive', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $foreign = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $foreign, 'event_id' => $other, 'name' => 'Zone étrangère', 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(HolderEntitlements::class)->grant($this->holder, $this->event, $foreign, now()->toIso8601String(), now()->addHour()->toIso8601String(), $this->admin))->toThrow(ValidationException::class);
});

it('liste la zone du site avec sa politique du site, et enregistre la politique dans le site (une seule politique par zone)', function () {
    ($this->policy)(true, false);
    $row = collect(app(HolderEntitlements::class)->zones($this->event))->firstWhere('id', $this->siteZone);
    expect($row)->not->toBeNull()->and((bool) $row->permanent_allowed)->toBeTrue()->and((bool) $row->offline_entry_allowed)->toBeFalse();
    app(HolderEntitlements::class)->savePolicy($this->event, $this->siteZone, 'NEUTRE-TEST', true, true, $this->admin);
    expect((bool) DB::table('site_zone_policies')->where('zone_id', $this->siteZone)->value('offline_entry_allowed'))->toBeTrue()
        ->and(DB::table('zone_policies')->where('zone_id', $this->siteZone)->exists())->toBeFalse();
});

it('laisse entrer le titulaire dans l’activité par une zone du site ouverte aux permanents, et le refuse sinon', function () {
    ($this->grant)();
    ($this->policy)(true);
    ($this->control)()->assertOk()->assertJson(['valid' => true]);
    ($this->control)(['type' => 'zone', 'id' => $this->siteZone])->assertOk()->assertJson(['valid' => true]);
    ($this->policy)(false);
    ($this->control)()->assertOk()->assertJson(['valid' => false]);
});

it('copie sur la tablette la politique de la zone du site visée par une habilitation de l’activité', function () {
    ($this->grant)();
    ($this->policy)(true, true);
    $policies = collect(app(PermanentAccess::class)->snapshot($this->event, null)['policies']);
    $mine = $policies->firstWhere('zone_id', $this->siteZone);
    expect($mine)->not->toBeNull()->and($mine['permanent_allowed'])->toBeTrue()->and($mine['offline_entry_allowed'])->toBeTrue();
});
