<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\Devices;
use App\Services\Poste\ListSnapshot;
use App\Services\Poste\PostCapabilities;
use App\Services\SiteConfiguration;
use App\Services\ZoneAccess;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * V1 / PR 1 — socle du site : postes permanents, capacités en données, affectation des tablettes (plusieurs par poste), relation poste–zone portée par le poste.
 * Contrat : docs/ailct/V1-INVARIANTS-SITE-ET-CONTROLE.md. Données fictives uniquement.
 */

function saUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif SA', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function saPost(string $code): string
{
    return (string) DB::table('checkpoints')->where('code', $code)->value('id');
}

function saDevice(string $name, ?string $point = null): Device
{
    return Device::create(['event_id' => DemoSeeder::id('event'), 'name' => $name, 'station' => null, 'point_code' => $point]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = saUser('administrateur');
    $this->site = app(SiteConfiguration::class);
});

// --- Équivalence avec le contrat V1 antérieur (S02) ------------------------------------------------------------------------------

it('livre les cinq postes permanents du site avec la configuration du contrat V1 antérieur', function () {
    $legacy = ['poste_1' => [], 'poste_2' => ['accueil', 'scan', 'sortie'], 'poste_3' => [], 'poste_4' => [], 'soute' => ['scan']];
    expect(DB::table('checkpoints')->orderBy('code')->pluck('code')->all())->toBe(['poste_1', 'poste_2', 'poste_3', 'poste_4', 'soute']);
    foreach ($legacy as $code => $capabilities) {
        $device = saDevice('Tablette fictive '.$code, $code);
        expect(PostCapabilities::capabilities($device))->toBe($capabilities)
            ->and(PostCapabilities::requiresDirection($device))->toBe($code === 'soute');
    }
    // une tablette sans poste (ancien point « hotel ») et une tablette sans point n'ont aucune capacité de poste
    expect(PostCapabilities::capabilities(saDevice('Tablette sans poste', 'hotel')))->toBe([])
        ->and(PostCapabilities::capabilities(saDevice('Tablette inconnue')))->toBe([]);
    expect(DB::table('sites')->count())->toBe(1)->and(DB::table('zones')->whereNull('site_id')->count())->toBe(0);
});

it('refuse en base un point de contrôle inconnu et garde la synchronisation entre le code hérité et le poste', function () {
    expect(fn () => DB::transaction(fn () => saDevice('Tablette fictive refusée', 'poste_9')))->toThrow(QueryException::class);
    $device = saDevice('Tablette fictive synchronisée', 'poste_3');
    expect(DB::table('devices')->where('id', $device->id)->value('checkpoint_id'))->toBe(saPost('poste_3'));
    DB::table('devices')->where('id', $device->id)->update(['point_code' => 'soute']);
    expect(DB::table('devices')->where('id', $device->id)->value('checkpoint_id'))->toBe(saPost('soute'));
    DB::table('devices')->where('id', $device->id)->update(['checkpoint_id' => saPost('poste_1')]);
    expect(DB::table('devices')->where('id', $device->id)->value('point_code'))->toBe('poste_1');
});

// --- Configuration des postes (données, jamais du code) ---------------------------------------------------------------------------

it('active une capacité réellement implémentée sur un poste, la trace, et la fait suivre à la copie de la tablette', function () {
    $device = saDevice('Tablette fictive P1', 'poste_1');
    $before = app(ListSnapshot::class)->version($device);
    expect(PostCapabilities::allows($device, 'scan'))->toBeFalse();
    $this->site->updateCheckpoint(saPost('poste_1'), ['name' => 'Poste 1', 'function_label' => 'Entrée principale', 'capabilities' => ['scan'], 'direction_required' => false], 1, $this->admin);
    expect(PostCapabilities::capabilities($device->fresh()))->toBe(['scan'])
        ->and(app(ListSnapshot::class)->version($device->fresh()))->not->toBe($before);
    $audit = DB::table('audit_logs')->where('action', 'poste.configure')->latest('id')->first();
    expect(json_decode($audit->details, true))->toMatchArray(['code' => 'poste_1'])
        ->and(json_decode($audit->details, true)['apres']['capacites'])->toBe(['scan']);
    $snapshot = app(ListSnapshot::class)->build($device->fresh());
    expect($snapshot['poste']['capabilities'])->toBe(['scan'])->and($snapshot['poste']['direction_required'])->toBeFalse();
});

it('refuse toute configuration incohérente ou non implémentée', function () {
    $post = saPost('poste_3');
    expect(fn () => $this->site->updateCheckpoint($post, ['name' => 'Poste 3', 'capabilities' => ['vehicules']], 1, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->site->updateCheckpoint($post, ['name' => 'Poste 3', 'capabilities' => ['accueil'], 'direction_required' => true], 1, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->site->updateCheckpoint($post, ['name' => '', 'capabilities' => []], 1, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('checkpoints')->where('id', $post)->update(['capabilities' => json_encode(['vehicules'])])))->toThrow(QueryException::class);
    expect(DB::table('checkpoints')->where('id', $post)->value('version'))->toBe(1);
});

it('maîtrise la concurrence : une modification fondée sur une version dépassée est refusée', function () {
    $post = saPost('poste_4');
    $this->site->updateCheckpoint($post, ['name' => 'Poste 4', 'function_label' => 'Le Porton', 'capabilities' => ['scan']], 1, $this->admin);
    expect((int) DB::table('checkpoints')->where('id', $post)->value('version'))->toBe(2);
    expect(fn () => $this->site->updateCheckpoint($post, ['name' => 'Poste 4', 'capabilities' => []], 1, $this->admin))->toThrow(ValidationException::class)
        ->and(PostCapabilities::ofCheckpoint('poste_4'))->toBe(['scan']);
});

it('désactive un poste sans le supprimer : il perd ses capacités, refuse de nouvelles tablettes et garde son code', function () {
    $device = saDevice('Tablette fictive P2', 'poste_2');
    $this->site->setActive(saPost('poste_2'), false, 1, $this->admin);
    expect(PostCapabilities::capabilities($device->fresh()))->toBe([])->and(PostCapabilities::allows($device->fresh(), 'accueil'))->toBeFalse();
    expect(fn () => DB::transaction(fn () => saDevice('Tablette fictive refusée', 'poste_2')))->toThrow(QueryException::class);
    $other = saDevice('Tablette fictive autre');
    expect(fn () => $this->site->assign($other->id, saPost('poste_2'), null, $this->admin))->toThrow(ValidationException::class);
    $this->site->setActive(saPost('poste_2'), true, 2, $this->admin);
    expect(PostCapabilities::capabilities($device->fresh()))->toBe(['accueil', 'scan', 'sortie']);
    expect(fn () => DB::transaction(fn () => DB::table('checkpoints')->where('id', saPost('poste_2'))->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('checkpoints')->where('id', saPost('poste_2'))->update(['code' => 'poste_22'])))->toThrow(QueryException::class);
    expect(DB::table('audit_logs')->whereIn('action', ['poste.desactive', 'poste.reactive'])->count())->toBe(2);
});

it('crée un poste sans toucher au code, et une tablette peut lui être affectée', function () {
    $id = $this->site->createCheckpoint(['code' => 'poste_6', 'name' => 'Poste 6', 'function_label' => 'Point fictif', 'capabilities' => ['scan', 'sortie'], 'direction_required' => true], $this->admin);
    expect(fn () => $this->site->createCheckpoint(['code' => 'poste_6', 'name' => 'Doublon'], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->site->createCheckpoint(['code' => 'Mauvais Code', 'name' => 'X'], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->site->createCheckpoint(['code' => 'hotel', 'name' => 'X'], $this->admin))->toThrow(ValidationException::class);
    $device = app(Devices::class)->create(DemoSeeder::id('event'), 'Tablette fictive P6', 'Poste fictif', $this->admin, 'poste_6');
    expect($device->fresh()->point_code)->toBe('poste_6')->and(DB::table('devices')->where('id', $device->id)->value('checkpoint_id'))->toBe($id)
        ->and(PostCapabilities::capabilities($device->fresh()))->toBe(['scan', 'sortie'])->and(PostCapabilities::requiresDirection($device->fresh()))->toBeTrue();
    expect(PostCapabilities::points())->toHaveKey('poste_6')->toHaveKey('hotel');
});

// --- Tablettes : identité, affectation, historique ---------------------------------------------------------------------------------

it('permet plusieurs tablettes par poste et historise chaque affectation', function () {
    $a = saDevice('Tablette fictive A');
    $b = saDevice('Tablette fictive B');
    $this->site->assign($a->id, saPost('poste_2'), 'Mise en service', $this->admin);
    $this->site->assign($b->id, saPost('poste_2'), null, $this->admin);
    expect(DB::table('devices')->whereIn('id', [$a->id, $b->id])->where('checkpoint_id', saPost('poste_2'))->count())->toBe(2);
    $this->site->assign($a->id, saPost('soute'), 'Remplacement', $this->admin);
    $history = DB::table('device_assignments')->where('device_id', $a->id)->orderBy('assigned_at')->orderBy('created_at')->get();
    expect($history->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('device_assignments')->where('device_id', $a->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('device_assignments')->where('device_id', $a->id)->whereNull('ended_at')->value('checkpoint_id'))->toBe(saPost('soute'))
        ->and(DB::table('device_assignments')->where('device_id', $a->id)->whereNull('ended_at')->value('reason'))->toBe('Remplacement')
        ->and(DB::table('device_assignments')->where('device_id', $a->id)->whereNull('ended_at')->value('assigned_by'))->toBe($this->admin->id);
    expect(DB::table('audit_logs')->where('action', 'tablette.affectee')->count())->toBe(3);
});

it('garantit en base une seule affectation ouverte par tablette', function () {
    $device = saDevice('Tablette fictive C', 'poste_3');
    expect(fn () => DB::transaction(fn () => DB::table('device_assignments')->insert(['id' => (string) Str::uuid(), 'device_id' => $device->id, 'checkpoint_id' => saPost('poste_1'), 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});

it('refuse d’affecter une tablette révoquée et de retirer de son poste une tablette enrôlée', function () {
    $revoked = saDevice('Tablette fictive révoquée', 'poste_3');
    DB::table('devices')->where('id', $revoked->id)->update(['revoked_at' => now()]);
    expect(fn () => $this->site->assign($revoked->id, saPost('poste_1'), null, $this->admin))->toThrow(ValidationException::class);
    $enrolled = saDevice('Tablette fictive enrôlée', 'poste_3');
    DB::table('devices')->where('id', $enrolled->id)->update(['token_hash' => hash('sha256', 'jeton fictif'), 'enrolled_at' => now()]);
    expect(fn () => $this->site->assign($enrolled->id, null, null, $this->admin))->toThrow(ValidationException::class);
    $free = saDevice('Tablette fictive libre');
    $this->site->assign($free->id, null, null, $this->admin);
    expect(DB::table('devices')->where('id', $free->id)->value('checkpoint_id'))->toBeNull();
});

// --- Relation poste–zone portée par le poste -------------------------------------------------------------------------------------

it('juge les mêmes zones pour toutes les tablettes d’un poste, et aucune pour les tablettes d’un autre poste', function () {
    $access = app(ZoneAccess::class);
    $one = saDevice('Tablette fictive 1', 'poste_2');
    $two = saDevice('Tablette fictive 2', 'poste_2');
    $other = saDevice('Tablette fictive 3', 'poste_3');
    $access->map(DemoSeeder::id('event'), saPost('poste_2'), DemoSeeder::id('zone1'), $this->admin);
    $judged = fn (Device $d) => $access->judgedZones(DemoSeeder::id('event'), ['type' => 'poste', 'id' => $d->id]);
    expect($judged($one))->toBe([DemoSeeder::id('zone1')])->and($judged($two))->toBe([DemoSeeder::id('zone1')])->and($judged($other))->toBeNull();
    expect(fn () => $access->map(DemoSeeder::id('event'), saPost('poste_2'), DemoSeeder::id('zone1'), $this->admin))->toThrow(ValidationException::class);
    // la copie hors ligne de chaque tablette du poste porte la correspondance
    $snapshot = app(ListSnapshot::class)->build($one->fresh());
    expect($snapshot['point_zones']->where('device_id', $two->id)->pluck('zone_id')->all())->toBe([DemoSeeder::id('zone1')]);
    // une tablette qui change de poste change de zones jugées
    $this->site->assign($one->id, saPost('poste_3'), null, $this->admin);
    expect($judged($one->fresh()))->toBeNull();
    $this->site->setActive(saPost('poste_3'), false, 1, $this->admin);
    expect(fn () => $access->map(DemoSeeder::id('event'), saPost('poste_3'), DemoSeeder::id('zone1'), $this->admin))->toThrow(ValidationException::class);
});

// --- Écran « Site et accès » : serveur ---------------------------------------------------------------------------------------------

it('sert l’écran Site et accès avec les données réelles, réservé à l’administrateur', function () {
    $this->site->assign(saDevice('Tablette fictive écran')->id, saPost('poste_2'), null, $this->admin);
    $this->actingAs($this->admin)->get('/administration/site')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/SiteAcces')->has('site')->has('checkpoints', 5)
        ->where('checkpoints.1.code', 'poste_2')->where('checkpoints.1.capabilities', ['accueil', 'scan', 'sortie'])->where('checkpoints.4.direction_required', true)
        ->has('catalogue', 3)->has('history')->has('unassigned'));
    foreach (['superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $this->actingAs(saUser($role))->get('/administration/site')->assertForbidden();
    }
});

it('enregistre par les routes la configuration, l’activation et l’affectation, avec refus lisible en cas de conflit', function () {
    $this->actingAs($this->admin);
    $post = saPost('poste_1');
    $this->put('/administration/site/postes/'.$post, ['name' => 'Poste 1', 'function_label' => 'Entrée principale', 'capabilities' => ['scan'], 'direction_required' => '1', 'version' => 1])->assertSessionHasNoErrors();
    expect(PostCapabilities::ofCheckpoint('poste_1'))->toBe(['scan']);
    $this->put('/administration/site/postes/'.$post, ['name' => 'Poste 1', 'capabilities' => [], 'version' => 1])->assertSessionHasErrors('version');
    $this->put('/administration/site/postes/'.$post, ['name' => 'Poste 1', 'capabilities' => ['vehicules'], 'version' => 2])->assertSessionHasErrors('capabilities.0');
    $this->post('/administration/site/postes/'.$post.'/activation', ['active' => false, 'version' => 2])->assertSessionHasNoErrors();
    expect((bool) DB::table('checkpoints')->where('id', $post)->value('active'))->toBeFalse();
    $this->post('/administration/site/postes', ['code' => 'poste_7', 'name' => 'Poste 7', 'capabilities' => ['sortie']])->assertSessionHasNoErrors();
    expect(PostCapabilities::ofCheckpoint('poste_7'))->toBe(['sortie']);
    $device = saDevice('Tablette fictive route');
    $this->post('/administration/site/tablettes/'.$device->id.'/affectation', ['checkpoint_id' => saPost('poste_2'), 'reason' => 'Essai'])->assertSessionHasNoErrors();
    expect(DB::table('devices')->where('id', $device->id)->value('checkpoint_id'))->toBe(saPost('poste_2'));
    $this->put('/administration/site', ['name' => 'Site fictif'])->assertSessionHasNoErrors();
    expect(DB::table('sites')->value('name'))->toBe('Site fictif');
});
