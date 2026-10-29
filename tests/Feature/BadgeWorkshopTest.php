<?php

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\QrSvg;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

function workshopAccount(string $role): User
{
    $user = User::create(['name' => 'Recette fictive atelier', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = workshopAccount('administrateur');
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $this->batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 200, $this->admin);
    $this->options = ['event' => DemoSeeder::id('event'), 'batch' => $this->batch->id, 'design' => 'visiteur', 'title' => 'Site fictif IKOMA', 'primary' => '#0066B3', 'accent' => '#F75C03', 'orientation' => 'paysage', 'format' => 'a4', 'cols' => 2, 'rows' => 5, 'side' => 'recto'];
    $this->actingAs($this->admin);
});

it('présente les événements et lots sans transmettre les jetons ou les titulaires dans les props', function () {
    $this->get('/administration/badges/modeles')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/BadgeWorkshop')->has('events')->has('batches')->missing('badges')->missing('visitors'));
});

it('imprime 200 badges sans changer les jetons, statuts, réservations ou associations', function () {
    $before = DB::table('badges')->orderBy('id')->get()->toJson();
    $assignments = DB::table('badge_assignments')->orderBy('id')->get()->toJson();
    $response = $this->get('/administration/badges/modeles/imprimer?'.http_build_query($this->options))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(substr_count($response->getContent(), '<article class="access-card '))->toBe(200)
        ->and(substr_count($response->getContent(), '<section class="sheet"'))->toBe(20);
    $first = Badge::where('badge_batch_id', $this->batch->id)->orderBy('code')->firstOrFail();
    $response->assertSee(app(QrSvg::class)->render(QrSvg::payload($first->qr_token), 'QR du badge '.$first->code), false)->assertDontSee('Visiteur fictif')->assertDontSee('Yao Brou')->assertDontSee('Espace VIP');
    expect(DB::table('badges')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('badge_assignments')->orderBy('id')->get()->toJson())->toBe($assignments);
    $audit = DB::table('audit_logs')->where('action', 'badge.impression_preparee')->orderByDesc('id')->first();
    expect(json_decode($audit->details, true)['nombre'])->toBe(200)->and($audit->details)->not->toContain($first->qr_token);
});

it('prévisualise le même badge que le tirage PVC avec les dimensions physiques dans les deux orientations', function (string $orientation, string $size) {
    $options = [...$this->options, 'format' => 'pvc', 'from' => 401, 'to' => 401, 'orientation' => $orientation];
    $preview = $this->postJson('/administration/badges/modeles/apercu', $options)->assertOk()->assertJsonPath('count', 1)->assertJsonPath('first', 'B-401');
    $response = $this->get('/administration/badges/modeles/imprimer?'.http_build_query($options))->assertOk();
    $response->assertSee($preview->json('html'), false)->assertSee('size: '.$size, false);
    expect(substr_count($response->getContent(), '<article class="access-card '))->toBe(1);
})->with([['paysage', '85.6mm 53.98mm'], ['portrait', '53.98mm 85.6mm']]);

it('refuse les sélections incohérentes et les planches qui réduiraient les badges', function (array $changes, string $field) {
    $this->postJson('/administration/badges/modeles/apercu', [...$this->options, ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['cols' => 3], 'cols'], [['orientation' => 'portrait', 'rows' => 5], 'cols'],
    [['from' => 402, 'to' => 401], 'to'], [['from' => 999999], 'from'],
    [['primary' => 'url(https://exemple.test)'], 'primary'], [['design' => '../../secret'], 'design'],
    [['mode' => 'nominatif', 'full_name' => 'Ne jamais imprimer'], 'mode'],
]);

it('refuse plus de 1000 badges plutôt que de tronquer silencieusement', function () {
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 1001, $this->admin);
    $this->postJson('/administration/badges/modeles/apercu', [...$this->options, 'batch' => $batch->id])->assertUnprocessable()->assertJsonValidationErrors('to');
});

it('sépare strictement les événements pour un même numéro de badge', function () {
    $event = Str::uuid()->toString();
    DB::table('events')->insert(['id' => $event, 'name' => 'Autre événement fictif', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/administration/badges/modeles/apercu', [...$this->options, 'event' => $event])->assertUnprocessable()->assertJsonValidationErrors('batch');
});

it('conserve les règles de contrôle après tirage, association, annulation et restitution', function () {
    $agent = workshopAccount('agent_accueil');
    $supervisor = workshopAccount('superviseur');
    $badges = Badge::where('badge_batch_id', $this->batch->id)->orderBy('code')->limit(2)->get();
    $service = app(BadgeService::class);
    $badge = $badges[0];
    $service->associer($badge, DemoSeeder::id('visitor1'), $agent);
    $this->get('/administration/badges/modeles/imprimer?'.http_build_query([...$this->options, 'design' => 'vip']))->assertOk();
    expect($service->controler(QrSvg::payload($badge->qr_token))->valid)->toBeTrue();
    $service->annuler($badge, $supervisor, 'Recette fictive');
    $this->postJson('/administration/badges/modeles/apercu', $this->options)->assertOk()->assertJsonPath('inactive', 1);
    expect($service->controler(QrSvg::payload($badge->qr_token))->valid)->toBeFalse()->and($badge->fresh()->status)->toBe(BadgeStatus::Cancelled);
    $other = $badges[1];
    $service->associer($other, DemoSeeder::id('visitor4'), $agent);
    DB::table('settings')->where('key', 'badge_reutilisable')->update(['value' => 'non']);
    $service->restituer($other, $agent);
    $this->get('/administration/badges/modeles/imprimer?'.http_build_query($this->options))->assertOk();
    expect($service->controler(QrSvg::payload($other->qr_token))->valid)->toBeFalse()->and($other->fresh()->status)->toBe(BadgeStatus::Closed);
});

it('enregistre les options dans les réglages de cet événement et échappe les textes fournis', function () {
    $title = '<img src=x onerror=alert(1)>';
    $this->put('/administration/badges/modeles', [...$this->options, 'title' => $title])->assertRedirect();
    expect(DB::table('settings')->where('key', 'badge_atelier_'.DemoSeeder::id('event').'_title')->value('value'))->toBe($title);
    $this->postJson('/administration/badges/modeles/apercu', [...$this->options, 'title' => $title, 'full_name' => 'Secret fictif', 'photo' => 'https://exemple.test/p.jpg'])
        ->assertOk()->assertDontSee('<img src=x', false)->assertDontSee('Secret fictif')->assertDontSee('https://exemple.test/p.jpg');
});

it('rend le verso générique sans QR ni données nominatives', function () {
    $preview = $this->postJson('/administration/badges/modeles/apercu', [...$this->options, 'side' => 'verso'])->assertOk();
    expect($preview->json('html'))->toContain('Consignes de visite')->not->toContain('<svg')->not->toContain('Visiteur fictif');
});

it('applique les permissions à toutes les entrées de l’atelier', function (string $role) {
    $this->actingAs(workshopAccount($role));
    $this->get('/administration/badges/modeles')->assertForbidden();
    $this->postJson('/administration/badges/modeles/apercu', $this->options)->assertForbidden();
    $this->putJson('/administration/badges/modeles', $this->options)->assertForbidden();
    $this->get('/administration/badges/modeles/imprimer?'.http_build_query($this->options))->assertForbidden();
})->with(['agent_accueil', 'superviseur', 'hote']);

it('exige la connexion et la double authentification de l’administrateur', function () {
    auth()->logout();
    $this->get('/administration/badges/modeles')->assertRedirect('/login');
    $this->admin->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($this->admin)->get('/administration/badges/modeles')->assertRedirect('/securite');
});
