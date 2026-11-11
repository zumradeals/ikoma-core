<?php

use App\Models\Device;
use App\Models\User;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use App\Services\Poste\ListSnapshot;
use App\Services\SiteConfiguration;
use App\Services\SiteEntitlements;
use App\Services\SiteMovements;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

uses(DatabaseTransactions::class);

const SC_TOKEN = '7777777777777777777777777777777777777777777777777777777777777777';

function scUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif site', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function scHeaders(): array
{
    return ['X-Device-Token' => SC_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

/** @return array{0: string, 1: string} titulaire fictif avec sa carte émise et activée : [identifiant interne, jeton QR] */
function scCard(string $category = 'AILCT-01'): array
{
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive Site '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => $category], test()->admin);
    app(HolderCards::class)->issue($holder, test()->admin);
    $token = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->where('k.status', 'active')->value('b.qr_token');

    return [$holder, $token];
}

function scControl(string $token)
{
    return test()->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$token, 'point' => null], scHeaders());
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = scUser('administrateur');
    $this->agent = scUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $this->event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $this->event, 'name' => 'Événement fictif contrôle du site', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'created_at' => now(), 'updated_at' => now()]);
    $this->activityZone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $this->activityZone, 'event_id' => $this->event, 'name' => 'Zone d’activité fictive', 'est_accueil' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('zone_policies')->insert(['zone_id' => $this->activityZone, 'event_id' => $this->event, 'classification' => 'NEUTRE-TEST', 'permanent_allowed' => true, 'offline_entry_allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $this->device, 'event_id' => $this->event, 'name' => 'Poste fictif', 'station' => 'Entrée', 'point_code' => 'poste_2', 'token_hash' => hash('sha256', SC_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->siteZone = app(SiteConfiguration::class)->createSiteZone('Hall fictif du site', $this->admin);
    $this->checkpoint = (string) DB::table('checkpoints')->where('code', 'poste_2')->value('id');
    $this->actingAs($this->agent);
});

function scOpenSite(bool $mapped = true, bool $policy = true): void
{
    if ($mapped) {
        app(SiteConfiguration::class)->mapSiteZone(test()->checkpoint, test()->siteZone, test()->admin);
    }
    if ($policy) {
        app(SiteEntitlements::class)->savePolicy(test()->siteZone, true, false, test()->admin);
    }
}

function scGrantSite(string $holder, string $from = '-1 hour', string $until = '+8 hours'): void
{
    app(SiteEntitlements::class)->grant($holder, test()->siteZone, now()->modify($from)->toIso8601String(), now()->modify($until)->toIso8601String(), test()->admin);
}

it('juge une carte permanente sur l’habilitation du site, écrit un mouvement du site sans événement ni participation', function () {
    scOpenSite();
    [$holder, $token] = scCard();
    scGrantSite($holder);

    scControl($token)->assertOk()->assertJson(['valid' => true, 'context' => 'site', 'permanent' => ['entered' => true]]);
    $row = DB::table('access_events')->where('holder_id', $holder)->first();
    expect($row->event_id)->toBeNull()->and($row->visitor_id)->toBeNull()->and($row->type)->toBe('entree');
    expect(DB::table('visitors')->where('holder_id', $holder)->where('event_id', $this->event)->count())->toBe(0);
    expect(app(SiteMovements::class)->count())->toBe(1);

    scControl($token)->assertOk()->assertJson(['valid' => true, 'context' => 'site', 'permanent' => ['entered' => false]]);
    expect(DB::table('access_events')->where('holder_id', $holder)->where('type', 'controle')->count())->toBe(1)->and(app(SiteMovements::class)->count())->toBe(1);
});

it('enregistre la sortie du site sans condition d’habilitation, puis retombe sur l’activité quand la personne n’est pas présente sur le site', function () {
    scOpenSite();
    [$holder, $token] = scCard();
    scGrantSite($holder);
    scControl($token)->assertOk();

    DB::table('holder_site_entitlements')->where('holder_id', $holder)->update(['revoked_at' => now(), 'revoked_by' => $this->admin->id, 'revocation_reason' => 'Fin fictive']);
    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token], scHeaders())->assertOk()->assertJson(['ok' => true, 'context' => 'site']);
    expect(app(SiteMovements::class)->count())->toBe(0);
    expect(DB::table('access_events')->where('holder_id', $holder)->where('type', 'sortie')->whereNull('event_id')->count())->toBe(1);

    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token], scHeaders())->assertJson(['ok' => false, 'reason' => 'sortie_sans_entree']);
});

it('garde le comportement d’activité quand le poste ne garde aucune zone du site', function () {
    scOpenSite(mapped: false);
    [$holder, $token] = scCard();
    scGrantSite($holder);
    app(HolderEntitlements::class)->grant($holder, $this->event, $this->activityZone, now()->subHour()->toIso8601String(), now()->addHours(8)->toIso8601String(), $this->admin);

    scControl($token)->assertOk()->assertJson(['valid' => true])->assertJsonMissing(['context' => 'site']);
    expect(DB::table('access_events')->where('holder_id', $holder)->count())->toBe(0);
    expect(DB::table('access_events')->where('event_id', $this->event)->where('type', 'entree')->count())->toBe(1);
});

it('applique l’habilitation d’activité quand le site n’accorde pas l’accès (zone sans politique)', function () {
    scOpenSite(policy: false);
    [$holder, $token] = scCard();
    scGrantSite($holder);
    app(HolderEntitlements::class)->grant($holder, $this->event, $this->activityZone, now()->subHour()->toIso8601String(), now()->addHours(8)->toIso8601String(), $this->admin);

    scControl($token)->assertOk()->assertJson(['valid' => true])->assertJsonMissing(['context' => 'site']);
    expect(DB::table('access_events')->whereNull('event_id')->count())->toBe(0);
});

it('refuse quand ni le site ni l’activité n’accordent l’accès, sans mouvement du site', function () {
    scOpenSite();
    [, $token] = scCard();

    scControl($token)->assertOk()->assertJson(['valid' => false, 'reason' => 'habilitation_absente']);
    expect(DB::table('access_events')->whereNull('event_id')->count())->toBe(0);
});

it('décide l’accès au site : habilitation valable, future, expirée, absente, carte perdue', function () {
    scOpenSite();
    $access = app(PermanentAccess::class);
    [$holder, $token] = scCard();
    $card = $access->resolveCard('IKOMA1:'.$token);
    $now = Carbon::now();

    expect($access->decideSite($card, [$this->siteZone], $now)['reason'])->toBe('habilitation_absente');
    scGrantSite($holder, '+2 days', '+3 days');
    expect($access->decideSite($card, [$this->siteZone], $now)['reason'])->toBe('habilitation_non_commencee');
    [$expiredHolder, $expiredToken] = scCard();
    scGrantSite($expiredHolder, '-3 days', '-2 days');
    expect($access->decideSite($access->resolveCard('IKOMA1:'.$expiredToken), [$this->siteZone], $now)['reason'])->toBe('habilitation_expiree');
    [$okHolder, $okToken] = scCard();
    scGrantSite($okHolder);
    $ok = $access->decideSite($access->resolveCard('IKOMA1:'.$okToken), [$this->siteZone], $now);
    expect($ok['allowed'])->toBeTrue()->and($ok['zones'])->toBe(['Hall fictif du site']);

    $lost = clone $access->resolveCard('IKOMA1:'.$okToken);
    $lost->card_status = 'perdue';
    expect($access->decideSite($lost, [$this->siteZone], $now)['reason'])->toBe('carte_perdue');
});

it('règle la politique d’une zone du site : ouverte, hors ligne seulement si ouverte, tracée', function () {
    $this->actingAs($this->admin);
    $this->put('/administration/habilitations/site/zones/'.$this->siteZone, ['permanent_allowed' => true, 'offline_entry_allowed' => true])->assertSessionHasNoErrors();
    $policy = DB::table('site_zone_policies')->where('zone_id', $this->siteZone)->first();
    expect((bool) $policy->permanent_allowed)->toBeTrue()->and((bool) $policy->offline_entry_allowed)->toBeTrue();
    $this->put('/administration/habilitations/site/zones/'.$this->siteZone, ['permanent_allowed' => false, 'offline_entry_allowed' => true])->assertSessionHasErrors('offline');
    expect(fn () => app(SiteEntitlements::class)->savePolicy((string) $this->activityZone, true, false, $this->admin))->toThrow(ValidationException::class);
    $this->get('/administration/habilitations')->assertOk()->assertInertia(fn ($p) => $p->where('site.zones.0.permanent_allowed', true)->where('site.zones.0.offline_entry_allowed', true));
    expect(DB::table('audit_logs')->where('action', 'zone_site.politique_modifiee')->count())->toBe(1);
});

function scReplay(string $type, string $token, string $knownAt): TestResponse
{
    $payload = ['code' => 'PC:'.hash('sha256', $token), 'point' => null];
    if ($type === 'controle') {
        $payload += ['local_valid' => true, 'known_at' => $knownAt];
    }

    return test()->postJson('/poste/api/sync', ['operations' => [['id' => (string) Str::uuid(), 'seq' => (int) DB::table('devices')->where('id', test()->device)->value('sync_seq') + 1, 'type' => $type, 'agent_id' => test()->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => $payload]]],
        ['X-Device-Token' => SC_TOKEN, 'Accept' => 'application/json'])->assertOk();
}

it('remet à la tablette la copie du site : zones gardées, politiques, habilitations, et les titulaires concernés', function () {
    scOpenSite();
    [$holder] = scCard();
    scGrantSite($holder);
    $access = app(PermanentAccess::class);

    expect($access->snapshot($this->event)['site'])->toBeNull();
    $snapshot = $access->snapshot($this->event, $this->checkpoint);
    expect($snapshot['site']['zones'])->toBe([['id' => $this->siteZone, 'name' => 'Hall fictif du site']])
        ->and($snapshot['site']['policies'][0]['permanent_allowed'])->toBeTrue()
        ->and($snapshot['site']['entitlements'][0]['holder_id'])->toBe($holder);
    expect(collect($snapshot['holders'])->firstWhere('id', $holder)['entitlements'])->toBe([]);
});

it('change la version de la copie quand une habilitation du site est accordée ou retirée', function () {
    scOpenSite();
    [$holder] = scCard();
    $device = Device::query()->findOrFail($this->device);
    $before = app(ListSnapshot::class)->version($device);
    scGrantSite($holder);
    $granted = app(ListSnapshot::class)->version($device);
    expect($granted)->not->toBe($before);
    app(SiteEntitlements::class)->revoke((string) DB::table('holder_site_entitlements')->where('holder_id', $holder)->value('id'), 'Fin fictive', $this->admin);
    expect(app(ListSnapshot::class)->version($device))->not->toBe($granted);
});

it('rejoue une entrée faite hors ligne au titre du site, avec les garde-fous de confiance et de zone', function () {
    scOpenSite(policy: false);
    app(SiteEntitlements::class)->savePolicy($this->siteZone, true, true, $this->admin);
    app(PermanentAccess::class)->saveOfflineTrust(60, true, $this->admin);
    [$holder, $token] = scCard();
    scGrantSite($holder);

    scReplay('controle', $token, now()->subMinutes(2)->toIso8601String());
    $row = DB::table('access_events')->where('holder_id', $holder)->whereNull('event_id')->first();
    expect($row->type)->toBe('entree')->and(json_decode((string) $row->details, true)['differe'])->toBeTrue();
    expect(app(SiteMovements::class)->count())->toBe(1);
});

it('refuse de rejouer au titre du site une zone qui n’accepte pas le hors ligne, une confiance expirée ou absente', function () {
    scOpenSite(policy: false);
    app(PermanentAccess::class)->saveOfflineTrust(60, true, $this->admin);
    [$holder, $token] = scCard();
    scGrantSite($holder);

    app(SiteEntitlements::class)->savePolicy($this->siteZone, true, false, $this->admin);
    scReplay('controle', $token, now()->subMinutes(2)->toIso8601String());
    app(SiteEntitlements::class)->savePolicy($this->siteZone, true, true, $this->admin);
    scReplay('controle', $token, now()->subHours(5)->toIso8601String());
    app(PermanentAccess::class)->saveOfflineTrust(0, false, $this->admin);
    scReplay('controle', $token, now()->subMinutes(2)->toIso8601String());

    expect(DB::table('access_events')->where('holder_id', $holder)->count())->toBe(0);
});

it('rejoue une sortie faite hors ligne dans le journal du site, sans condition', function () {
    scOpenSite();
    [$holder, $token] = scCard();
    scGrantSite($holder);
    scControl($token)->assertOk();
    expect(app(SiteMovements::class)->count())->toBe(1);

    scReplay('sortie', $token, now()->toIso8601String());
    expect(app(SiteMovements::class)->count())->toBe(0);
    expect(DB::table('access_events')->where('holder_id', $holder)->where('type', 'sortie')->whereNull('event_id')->count())->toBe(1);
});
