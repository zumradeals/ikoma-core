<?php

use App\Models\User;
use App\Services\CardLifecycle;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use App\Services\SiteConfiguration;
use App\Services\SiteEntitlements;
use App\Services\SiteMovements;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const SM_TOKEN = '8888888888888888888888888888888888888888888888888888888888888888';

function smUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif mode site', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function smHeaders(): array
{
    return ['X-Device-Token' => SM_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

/** @return array{0: string, 1: string} */
function smCard(): array
{
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive Mode Site '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => 'AILCT-01'], test()->admin);
    app(HolderCards::class)->issue($holder, test()->admin);
    $token = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->where('k.status', 'active')->value('b.qr_token');

    return [$holder, $token];
}

function smControl(string $code)
{
    return test()->postJson('/poste/api/controle', ['code' => $code, 'point' => null], smHeaders());
}

function smGrant(string $holder): void
{
    app(SiteEntitlements::class)->grant($holder, test()->siteZone, now()->subHour()->toIso8601String(), now()->addHours(8)->toIso8601String(), test()->admin);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = smUser('administrateur');
    $this->agent = smUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    // Tablette SANS activité en cours (mode site), affectée au poste « poste_2 ».
    $this->device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $this->device, 'event_id' => null, 'name' => 'Tablette fictive du site', 'station' => 'Entrée du site', 'point_code' => 'poste_2', 'token_hash' => hash('sha256', SM_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->siteZone = app(SiteConfiguration::class)->createSiteZone('Hall fictif du site', $this->admin);
    $this->checkpoint = (string) DB::table('checkpoints')->where('code', 'poste_2')->value('id');
    app(SiteConfiguration::class)->mapSiteZone($this->checkpoint, $this->siteZone, $this->admin);
    app(SiteEntitlements::class)->savePolicy($this->siteZone, true, true, $this->admin);
    $this->actingAs($this->agent);
});

it('remet une copie du site à une tablette sans activité, sans événement ni visiteur', function () {
    [$holder] = smCard();
    smGrant($holder);

    $copy = $this->getJson('/poste/api/liste', smHeaders())->assertOk()->json();
    expect($copy['event'])->toBeNull()->and($copy['poste']['event_id'])->toBeNull()->and($copy['visitors'])->toBe([])->and($copy['badges'])->toBe([]);
    expect($copy['permanent']['site']['zones'][0]['name'])->toBe('Hall fictif du site')->and($copy['permanent']['site']['entitlements'][0]['holder_id'])->toBe($holder);
    $version = $this->getJson('/poste/api/liste/version', smHeaders())->assertOk()->json('version');
    expect($version)->toBe($copy['version']);

    app(SiteEntitlements::class)->revoke((string) DB::table('holder_site_entitlements')->where('holder_id', $holder)->value('id'), 'Fin fictive', $this->admin);
    expect($this->getJson('/poste/api/liste/version', smHeaders())->assertOk()->json('version'))->not->toBe($version);
});

it('contrôle une carte permanente au titre du site et compte les présents du site', function () {
    [$holder, $token] = smCard();
    smGrant($holder);

    smControl('IKOMA1:'.$token)->assertOk()->assertJson(['valid' => true, 'context' => 'site', 'permanent' => ['entered' => true]]);
    expect(DB::table('access_events')->where('holder_id', $holder)->whereNull('event_id')->where('type', 'entree')->count())->toBe(1);
    $this->getJson('/poste/api/presents', smHeaders())->assertOk()->assertJson(['count' => 1]);

    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token, 'point' => null], smHeaders())->assertOk()->assertJson(['ok' => true, 'context' => 'site']);
    $this->getJson('/poste/api/presents', smHeaders())->assertOk()->assertJson(['count' => 0]);
    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token, 'point' => null], smHeaders())->assertJson(['ok' => false]);
});

it('refuse par défaut sans habilitation du site et journalise le refus, sans mouvement d’entrée', function () {
    [$holder, $token] = smCard();

    smControl('IKOMA1:'.$token)->assertOk()->assertJson(['valid' => false, 'reason' => 'habilitation_absente', 'context' => 'site']);
    $row = DB::table('access_events')->where('holder_id', $holder)->first();
    expect($row->type)->toBe('controle')->and($row->event_id)->toBeNull()->and(json_decode((string) $row->details, true)['raison'])->toBe('habilitation_absente');
    expect(app(SiteMovements::class)->count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'controle.refuse')->count())->toBe(1);
});

it('refuse un poste qui ne garde aucune zone du site', function () {
    [$holder, $token] = smCard();
    smGrant($holder);
    DB::table('checkpoint_site_zones')->where('checkpoint_id', $this->checkpoint)->delete();

    smControl('IKOMA1:'.$token)->assertOk()->assertJson(['valid' => false, 'reason' => 'poste_sans_zone_site']);
    expect(app(SiteMovements::class)->count())->toBe(0);
});

it('refuse un badge de visiteur ou un code inconnu : aucune activité en cours', function () {
    smControl('IKOMA1:'.str_repeat('ab', 32))->assertOk()->assertJson(['valid' => false, 'reason' => 'aucune_activite']);
    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.str_repeat('cd', 32), 'point' => null], smHeaders())->assertJson(['ok' => false, 'reason' => 'aucune_activite']);
    $this->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.str_repeat('ab', 32), 'point' => ['type' => 'zone', 'id' => (string) Str::uuid()]], smHeaders())->assertStatus(422);
});

it('refuse les écrans d’accueil sans activité en cours mais laisse choisir une activité', function () {
    $this->getJson('/poste/api/contexte', smHeaders())->assertStatus(409)->assertJson(['code' => 'aucune_activite_en_cours']);
    $this->getJson('/poste/api/recherche?q=abc', smHeaders())->assertStatus(409)->assertJson(['code' => 'aucune_activite_en_cours']);
    $this->getJson('/poste/api/activites', smHeaders())->assertOk()->assertJson(['activites' => []]);
});

it('rejoue hors ligne une entrée du site et refuse toute autre opération sans activité', function () {
    [$holder, $token] = smCard();
    smGrant($holder);
    app(PermanentAccess::class)->saveOfflineTrust(60, true, $this->admin);
    $send = fn (string $type, array $payload, int $seq) => $this->postJson('/poste/api/sync', ['operations' => [['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => $type, 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => $payload]]],
        ['X-Device-Token' => SM_TOKEN, 'Accept' => 'application/json'])->assertOk();

    $send('controle', ['code' => 'PC:'.hash('sha256', $token), 'point' => null, 'local_valid' => true, 'known_at' => now()->subMinutes(2)->toIso8601String()], 1);
    expect(app(SiteMovements::class)->count())->toBe(1);

    $response = $send('valider', ['visitor_id' => (string) Str::uuid()], 2);
    expect(json_encode($response->json()))->toContain('aucune_activite_en_cours');
});

it('lève une alerte du site (sans événement, sans nom) pour un refus de carte, dédoublonnée sur 5 minutes', function () {
    [$holder, $token] = smCard();

    smControl('IKOMA1:'.$token)->assertOk();
    smControl('IKOMA1:'.$token)->assertOk();
    $alerts = DB::table('alerts')->whereNull('event_id')->get();
    expect($alerts)->toHaveCount(1)->and($alerts[0]->type)->toBe('controle_refuse')->and($alerts[0]->severity)->toBe('haute');
    expect($alerts[0]->message)->not->toContain((string) DB::table('permanent_holders')->where('id', $holder)->value('full_name'));
});

it('lève une alerte critique du site pour une carte perdue ou volée, au contrôle comme à la sortie', function () {
    [$holder, $token] = smCard();
    smGrant($holder);
    smControl('IKOMA1:'.$token)->assertOk()->assertJson(['valid' => true]);
    app(CardLifecycle::class)->revoke($holder, 'volee', null, $this->admin);

    smControl('IKOMA1:'.$token)->assertOk()->assertJson(['valid' => false, 'reason' => 'carte_volee']);
    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token, 'point' => null], smHeaders())->assertOk()->assertJson(['ok' => true, 'context' => 'site']);
    expect(DB::table('alerts')->whereNull('event_id')->where('severity', 'critique')->count())->toBe(2);
});

it('montre les alertes du site au superviseur, les compte avec celles de l’activité et les laisse prendre en compte', function () {
    [, $token] = smCard();
    smControl('IKOMA1:'.$token)->assertOk();
    $alert = (string) DB::table('alerts')->whereNull('event_id')->value('id');

    $this->actingAs(smUser('superviseur'));
    $this->get('/pilotage/alertes')->assertOk()->assertInertia(fn ($p) => $p->component('Pilotage/Alertes')->has('siteAlerts', 1)->where('openAlerts', 1));
    $this->post('/pilotage/alertes/'.$alert.'/prendre-en-compte')->assertRedirect();
    expect(DB::table('alerts')->where('id', $alert)->value('acknowledged_at'))->not->toBeNull();
    $this->get('/pilotage/alertes')->assertOk()->assertInertia(fn ($p) => $p->has('siteAlerts', 0)->where('openAlerts', 0));
    $this->get('/pilotage/alertes?etat=prises')->assertOk()->assertInertia(fn ($p) => $p->has('siteAlerts', 1));
});
