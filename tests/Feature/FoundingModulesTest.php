<?php

use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\FoundingModules;
use App\Services\Groups;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\ListSnapshot;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = User::create([
        'name' => 'Administrateur modules',
        'email' => Str::uuid().'@exemple.test',
        'password' => Str::password(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
});

function f06Switch(bool $active): array
{
    return [
        'module' => 'F06', 'active' => $active,
        'confirmation' => $active ? 'ACTIVER F06' : 'DESACTIVER F06',
    ];
}

it('garantit neuf modules fondateurs, avec F06 désactivé par défaut et aucun autre commutateur', function () {
    $modules = app(FoundingModules::class);
    expect(DB::table('settings')->where('key', 'module_f06_active')->value('value'))->toBe('0')
        ->and($modules->f06Active())->toBeFalse()
        ->and(count($modules->catalog()))->toBe(9)
        ->and(collect($modules->catalog())->where('toggleable', true)->pluck('id')->all())->toBe(['F06'])
        ->and(collect($modules->catalog())->every(fn ($m) => $m['included'] === true))->toBeTrue();

    $this->get('/administration/modules')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Modules')->has('modules', 9)->where('modules.5.id', 'F06')->where('modules.5.active', false));
    $this->get('/administration/groupes')->assertForbidden();
    $this->get('/administration')->assertOk();
    $this->get('/administration/badges')->assertOk();
    $this->get('/administration/postes')->assertOk();
});

it('active F06 gratuitement depuis administration puis peut le désactiver sans perdre ses groupes', function () {
    $count = DB::table('groups')->count();
    $auditBefore = (int) DB::table('audit_logs')->max('id');
    $this->put('/administration/modules/F06', f06Switch(true))->assertRedirect();
    expect(app(FoundingModules::class)->f06Active())->toBeTrue();
    $this->get('/administration/groupes')->assertOk();
    $this->get('/pilotage/groupes')->assertForbidden(); // administrateur sans permission de pilotage
    $this->put('/administration/modules/F06', f06Switch(false))->assertRedirect();
    expect(app(FoundingModules::class)->f06Active())->toBeFalse()
        ->and(DB::table('groups')->count())->toBe($count)
        ->and(DB::table('audit_logs')->where('id', '>', $auditBefore)->where('action', 'module.f06.exploitation')->count())->toBe(2);
    $this->put('/administration/modules/F06', f06Switch(true))->assertRedirect();
    expect(DB::table('groups')->count())->toBe($count);
});

it('refuse de désactiver pendant les opérations collectives ouvertes et protège les données', function () {
    $this->put('/administration/modules/F06', f06Switch(true))->assertRedirect();
    $id = DemoSeeder::id('group');
    DB::table('groups')->where('id', $id)->update(['opened_at' => now()]);
    $this->put('/administration/modules/F06', f06Switch(false))->assertSessionHasErrors('module');
    expect(app(FoundingModules::class)->f06Active())->toBeTrue();
    DB::table('groups')->where('id', $id)->update(['opened_at' => null]);
    DB::table('group_members')->where('group_id', $id)->limit(1)->update(['confirmed_at' => now(), 'confirmed_by' => $this->admin->id]);
    $this->put('/administration/modules/F06', f06Switch(false))->assertSessionHasErrors('module');
    expect(app(FoundingModules::class)->f06Active())->toBeTrue();
    DB::table('group_members')->where('group_id', $id)->update(['confirmed_at' => null, 'confirmed_by' => null]);
    $this->put('/administration/modules/F06', f06Switch(false))->assertRedirect();
    expect(app(FoundingModules::class)->f06Active())->toBeFalse();
});

it('exige un administrateur réel et une confirmation exacte, sans option sur F01-F05 ou F07-F09', function () {
    $this->put('/administration/modules/F06', ['module' => 'F06', 'active' => true, 'confirmation' => 'oui'])
        ->assertSessionHasErrors('confirmation');
    $this->put('/administration/modules/F06', ['module' => 'F01', 'active' => false, 'confirmation' => 'DESACTIVER F06'])
        ->assertSessionHasErrors('module');
    expect(app(FoundingModules::class)->f06Active())->toBeFalse();
    $agent = User::create(['name' => 'Agent', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $agent->assignRole('agent_accueil');
    $this->actingAs($agent);
    $this->put('/administration/modules/F06', f06Switch(true))->assertForbidden();
    $this->get('/administration/modules')->assertForbidden();
    expect(app(FoundingModules::class)->f06Active())->toBeFalse();
});

it('bloque les actions métier et les réservations collectives si F06 est inactif', function () {
    $g = Group::findOrFail(DemoSeeder::id('group'));
    expect(fn () => app(Groups::class)->create($g->event_id, 'Car interdit', null, null, $this->admin))
        ->toThrow(DeskRefused::class);
    expect(fn () => app(BadgeLots::class)->reserve(DemoSeeder::id('batch'), 1, 1, $g, $this->admin))
        ->toThrow(DeskRefused::class);
    expect(DB::table('groups')->count())->toBeGreaterThan(0);
});

it('change la version du snapshot tablette et retire les données collectives tant que F06 est inactif', function () {
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $snap = app(ListSnapshot::class);
    $initial = $snap->build($poste);
    expect($initial['settings']['f06_active'])->toBeFalse()
        ->and($initial['groups'])->toBe([])
        ->and($initial['members'])->toBe([]);
    $v0 = $snap->version($poste);
    $this->put('/administration/modules/F06', f06Switch(true))->assertRedirect();
    $v1 = $snap->version($poste);
    $enabled = $snap->build($poste);
    expect($v1)->not->toBe($v0)
        ->and($enabled['settings']['f06_active'])->toBeTrue()
        ->and(count($enabled['groups']))->toBeGreaterThan(0);
    $this->put('/administration/modules/F06', f06Switch(false))->assertRedirect();
    expect($snap->version($poste))->not->toBe($v1);
});
