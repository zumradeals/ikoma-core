<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\ListSnapshot;
use App\Services\Poste\PostCapabilities;
use App\Services\SiteConfiguration;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Lot A — correction du contrôle aux cinq postes : capacité de scan partout, différences conservées, reprise de configuration des installations existantes,
 * copie d'une tablette sans activité. Données fictives uniquement.
 */

const SC_TOKEN = '6666666666666666666666666666666666666666666666666666666666666666';

function scUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif SC', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function scHeaders(): array
{
    return ['X-Device-Token' => SC_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

function scPoint(?string $point): void
{
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => $point]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->agent = scUser('agent_accueil');
    $this->actingAs($this->agent);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', SC_TOKEN)]);
});

it('livre la capacité de scan aux cinq postes en gardant leurs différences', function () {
    $expected = ['poste_1' => ['scan'], 'poste_2' => ['accueil', 'scan', 'sortie'], 'poste_3' => ['scan'], 'poste_4' => ['scan'], 'soute' => ['scan']];
    foreach ($expected as $code => $capabilities) {
        expect(PostCapabilities::ofCheckpoint($code))->toBe($capabilities);
    }
    // l'accueil et la sortie ne sont ouverts qu'au poste 2 ; seul le sens obligatoire de la soute est posé
    expect(DB::table('checkpoints')->where('direction_required', true)->pluck('code')->all())->toBe(['soute']);
    expect(DB::table('checkpoints')->whereIn('code', ['poste_1', 'poste_3', 'poste_4', 'soute'])->whereRaw("jsonb_exists(capabilities, 'accueil') OR jsonb_exists(capabilities, 'sortie')")->count())->toBe(0);
});

it('applique la capacité aux tablettes : scan permis aux cinq postes, sortie et accueil seulement au poste 2, rien à l’hôtel ni sans poste', function (string $point, bool $sortie, bool $accueil) {
    scPoint($point);
    $direction = $point === 'soute' ? ['direction' => 'entree'] : [];
    expect($this->postJson('/poste/api/controle', ['code' => 'B-INCONNU'] + $direction, scHeaders())->status())->not->toBe(403);
    $out = $this->postJson('/poste/api/sortie', ['code' => 'B-INCONNU'], scHeaders());
    $sortie ? expect($out->status())->not->toBe(403) : $out->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $refuse = $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], scHeaders());
    $accueil ? expect($refuse->status())->not->toBe(403) : $refuse->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
})->with([['poste_1', false, false], ['poste_2', true, true], ['poste_3', false, false], ['poste_4', false, false], ['soute', false, false]]);

it('refuse le scan à une tablette sans poste ou dont le poste est désactivé', function () {
    foreach (['hotel', null] as $point) {
        scPoint($point);
        $this->postJson('/poste/api/controle', ['code' => 'B-INCONNU'], scHeaders())->assertForbidden();
    }
    scPoint('poste_3');
    DB::table('checkpoints')->where('code', 'poste_3')->update(['active' => false]);
    expect(PostCapabilities::capabilities(Device::findOrFail(DemoSeeder::id('device'))))->toBe([]);
    $this->postJson('/poste/api/controle', ['code' => 'B-INCONNU'], scHeaders())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
});

it('refuse le scan à un compte qui n’a pas la permission de contrôle, même au poste 2', function () {
    scPoint('poste_2');
    foreach (['hote', 'encadreur'] as $role) {
        $this->actingAs(scUser($role));
        $this->postJson('/poste/api/controle', ['code' => 'B-INCONNU'], scHeaders())->assertForbidden();
    }
});

it('reprend la configuration des installations existantes : ajoute seulement « scan » et ne touche à rien d’autre, sans effet à la seconde exécution', function () {
    // Installation existante avant la décision : postes 1, 3, 4 sans capacité ; l'administrateur a renommé et désactivé le poste 3, laissé l'accueil au poste 4 et retiré le scan du poste 2.
    DB::table('checkpoints')->where('code', 'poste_1')->update(['capabilities' => '[]']);
    DB::table('checkpoints')->where('code', 'poste_3')->update(['capabilities' => '[]', 'name' => 'Poste 3 (renommé)', 'active' => false]);
    DB::table('checkpoints')->where('code', 'poste_4')->update(['capabilities' => '["accueil"]']);
    DB::table('checkpoints')->where('code', 'poste_2')->update(['capabilities' => '["accueil","sortie"]']);
    $before = DB::table('checkpoints')->get()->keyBy('code');

    $migration = require database_path('migrations/2026_12_17_000000_enable_scan_on_five_posts.php');
    $migration->up();
    $after = DB::table('checkpoints')->get()->keyBy('code');

    $caps = fn (string $code) => PostCapabilities::normalize(json_decode((string) $after[$code]->capabilities, true));
    expect($caps('poste_1'))->toBe(['scan'])
        ->and($caps('poste_2'))->toBe(['accueil', 'scan', 'sortie'])
        ->and($caps('poste_3'))->toBe(['scan'])
        ->and($caps('poste_4'))->toBe(['accueil', 'scan'])   // l'accueil réglé par l'administrateur est conservé
        ->and($caps('soute'))->toBe(['scan']);
    foreach ($after as $code => $row) {
        expect($row->name)->toBe($before[$code]->name)
            ->and((bool) $row->active)->toBe((bool) $before[$code]->active)
            ->and((bool) $row->direction_required)->toBe((bool) $before[$code]->direction_required)
            ->and($row->function_label)->toBe($before[$code]->function_label)
            ->and($row->code)->toBe($before[$code]->code);
    }
    expect((bool) $after['soute']->direction_required)->toBeTrue();
    // la soute avait déjà le scan : sa version ne bouge pas ; les autres postes changent de version (les tablettes se resynchronisent)
    expect($after['soute']->version)->toBe($before['soute']->version)->and($after['poste_1']->version)->toBeGreaterThan($before['poste_1']->version);

    $migration->up(); // idempotent
    expect(DB::table('checkpoints')->get()->keyBy('code')->map(fn ($r) => $r->version)->all())->toBe($after->map(fn ($r) => $r->version)->all());
});

it('change la version de la copie de la tablette quand les capacités changent, pour qu’elle se mette à jour toute seule', function () {
    scPoint('poste_1');
    DB::table('checkpoints')->where('code', 'poste_1')->update(['capabilities' => '[]']);
    $before = app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device')));
    DB::table('checkpoints')->where('code', 'poste_1')->update(['capabilities' => '["scan"]']);
    expect(app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device'))))->not->toBe($before);
});

it('sert à une tablette sans activité affectée au poste 1 son poste, ses capacités et les zones qu’il garde (jamais d’événement fictif)', function () {
    $admin = scUser('administrateur');
    $device = Device::create(['event_id' => null, 'name' => 'Tablette fictive site', 'station' => 'Poste 1', 'point_code' => 'poste_1']);
    $postId = (string) DB::table('checkpoints')->where('code', 'poste_1')->value('id');
    app(SiteConfiguration::class)->mapSiteZone($postId, DemoSeeder::id('zone1'), $admin);

    $copy = app(ListSnapshot::class)->build($device->fresh());
    expect($copy['event'])->toBeNull()
        ->and($copy['poste']['point_code'])->toBe('poste_1')
        ->and($copy['poste']['capabilities'])->toBe(['scan'])
        // constat du lot A : la copie sans activité ne porte ni zones d'activité ni autres postes ; le point de contrôle de la tablette est donc son poste d'affectation
        ->and($copy['zones'])->toBe([])->and($copy['postes'])->toBe([])->and($copy['visitors'])->toBe([])->and($copy['badges'])->toBe([])
        // les zones gardées par le poste voyagent dans la copie des titulaires permanents (contrôle hors ligne des cartes)
        ->and(collect($copy['permanent']['site']['zones'])->pluck('id')->all())->toBe([DemoSeeder::id('zone1')]);
});
