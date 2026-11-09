<?php

use App\Models\User;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\ParticipationEntitlements;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\PersonRegistry;
use App\Services\Persons;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * V1 / lots P3 + P4 — gestion des habilitations depuis la fiche. Les opérations réutilisent les routes et services EXISTANTS de chaque moteur
 * (aucune route ni règle nouvelle) : ces tests garantissent, côté serveur et pas seulement dans l'interface, la séparation des deux moteurs, les permissions,
 * les préconditions, l'audit et la non-création d'identité. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function hbUser(string $role, array $extra = []): User
{
    $user = User::create(['name' => 'Compte fictif habilitations', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    foreach ($extra as $permission) {
        $user->givePermissionTo($permission);
    }
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/**
 * Personne fictive avec, au choix, un profil permanent et une habilitation permanente, une participation ouverte et une habilitation d'activité.
 *
 * @return array{person: string, holder: ?string, perm: ?string, participation: ?string, act: ?string}
 */
function hbPerson(bool $permanent, bool $activity): array
{
    $admin = test()->admin;
    $person = app(Persons::class)->create($admin, ['full_name' => 'Zzhabilitation '.Str::lower(Str::random(8))]);
    $out = ['person' => $person, 'holder' => null, 'perm' => null, 'participation' => null, 'act' => null];
    $from = now()->subDay()->toIso8601String();
    $until = now()->addDays(10)->toIso8601String();
    if ($activity) {
        $out['participation'] = app(Participations::class)->enrol($admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
        $out['act'] = app(ParticipationEntitlements::class)->grant($out['participation'], DemoSeeder::id('zone1'), 'standard', null, $from, $until, $admin);
    }
    if ($permanent) {
        $out['holder'] = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], $admin, false, $person);
        $out['perm'] = app(HolderEntitlements::class)->grant($out['holder'], DemoSeeder::id('event'), DemoSeeder::id('zone0'), $from, $until, $admin);
    }

    return $out;
}

/** @return array{permanentes: list<array<string, mixed>>, activites: list<array<string, mixed>>} */
function hbRights(string $person): array
{
    return app(PersonRegistry::class)->profile($person)['entitlements'];
}

function hbRight(string $person, string $id): array
{
    foreach ([...hbRights($person)['permanentes'], ...hbRights($person)['activites']] as $right) {
        if ($right['id'] === $id) {
            return $right;
        }
    }
    throw new RuntimeException('Habilitation absente de la fiche.');
}

function hbAudit(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = hbUser('administrateur');
    $this->agent = hbUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
});

// --- Consultation selon les droits détenus -------------------------------------------------------------------------------------

it('présente séparément les habilitations d’une personne qui n’a que des droits permanents, et les opérations que le moteur permet', function () {
    $p = hbPerson(true, false);
    $rights = hbRights($p['person']);
    expect($rights['permanentes'])->toHaveCount(1)->and($rights['activites'])->toBe([])
        ->and($rights['permanentes'][0]['origin'])->toBe('permanente')->and($rights['permanentes'][0]['actions'])->toBe(['retirer']);
    // Habilitation permanente : ni suspension, ni reprise, ni modification.
    expect($rights['permanentes'][0]['actions'])->not->toContain('suspendre')->and($rights['permanentes'][0]['actions'])->not->toContain('reprendre');
    $grant = app(PersonRegistry::class)->profile($p['person'])['grant'];
    expect($grant['permanent'])->not->toBeNull()->and($grant['permanent']['holder_id'])->toBe($p['holder'])
        ->and(array_column($grant['permanent']['events'], 'id'))->toContain(DemoSeeder::id('event'))->and($grant['activites'])->toBe([]);
});

it('conserve ses opérations à une personne qui n’a que des droits de participation, sans proposer de droit permanent', function () {
    $p = hbPerson(false, true);
    $rights = hbRights($p['person']);
    expect($rights['permanentes'])->toBe([])->and($rights['activites'])->toHaveCount(1)->and($rights['activites'][0]['actions'])->toBe(['suspendre', 'retirer']);
    $grant = app(PersonRegistry::class)->profile($p['person'])['grant'];
    expect($grant['permanent'])->toBeNull()->and($grant['activites'])->toHaveCount(1)->and($grant['activites'][0]['participation_id'])->toBe($p['participation'])
        ->and(array_column($grant['activites'][0]['zones'], 'id'))->toContain(DemoSeeder::id('zone1'));
});

it('sépare les deux ensembles pour une personne qui détient les deux types de droits', function () {
    $p = hbPerson(true, true);
    $rights = hbRights($p['person']);
    expect(array_column($rights['permanentes'], 'id'))->toBe([$p['perm']])->and(array_column($rights['activites'], 'id'))->toBe([$p['act']])
        ->and($rights['permanentes'][0]['state'])->toBe('active')->and($rights['activites'][0]['state'])->toBe('active');
});

// --- Indépendance des deux moteurs ---------------------------------------------------------------------------------------------

it('retire une habilitation permanente sans toucher aux droits de participation, et inversement pour une suspension', function () {
    $p = hbPerson(true, true);
    $this->actingAs($this->admin);

    $this->post('/administration/habilitations/'.$p['perm'].'/retirer', ['reason' => 'Fin de mission fictive'])->assertSessionHasNoErrors();
    expect(hbRight($p['person'], $p['perm'])['state'])->toBe('retiree')->and(hbRight($p['person'], $p['perm'])['actions'])->toBe([])
        ->and(hbRight($p['person'], $p['act'])['state'])->toBe('active');

    $q = hbPerson(true, true);
    $this->post('/administration/acces/habilitations/'.$q['act'].'/suspendre', ['reason' => 'Contrôle fictif'])->assertSessionHasNoErrors();
    expect(hbRight($q['person'], $q['act'])['state'])->toBe('suspendue')->and(hbRight($q['person'], $q['act'])['actions'])->toBe(['reprendre', 'retirer'])
        ->and(hbRight($q['person'], $q['perm'])['state'])->toBe('active');
    $this->post('/administration/acces/habilitations/'.$q['act'].'/reprendre')->assertSessionHasNoErrors();
    expect(hbRight($q['person'], $q['act'])['state'])->toBe('active');
});

it('ne révoque pas les habilitations permanentes quand une participation est clôturée', function () {
    $p = hbPerson(true, true);

    app(Participations::class)->close($this->admin, $p['participation'], 'Fin du stage fictif');

    expect(hbRight($p['person'], $p['act'])['state'])->toBe('participation_close')->and(hbRight($p['person'], $p['perm'])['state'])->toBe('active')
        ->and(DB::table('holder_entitlements')->where('id', $p['perm'])->value('revoked_at'))->toBeNull()
        ->and(DB::table('participation_entitlements')->where('id', $p['act'])->value('revoked_at'))->toBeNull();
    // Plus aucune attribution d'activité n'est proposée pour une participation clôturée ; l'attribution permanente reste possible.
    $grant = app(PersonRegistry::class)->profile($p['person'])['grant'];
    expect($grant['activites'])->toBe([])->and($grant['permanent'])->not->toBeNull();
});

// --- Opérations par les routes existantes : résultats, préconditions, audit -------------------------------------------------

it('accorde, suspend, reprend et retire par les routes existantes, avec audit et sans créer d’identité', function () {
    $person = app(Persons::class)->create($this->admin, ['full_name' => 'Zzhabilitation '.Str::lower(Str::random(8))]);
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif'], $this->admin, false, $person);
    $before = ['persons' => DB::table('persons')->count(), 'granted' => hbAudit('habilitation.accordee'), 'suspended' => hbAudit('habilitation.suspendue'), 'resumed' => hbAudit('habilitation.reprise'), 'revoked' => hbAudit('habilitation.retiree')];
    $revision = (int) DB::table('events')->where('id', DemoSeeder::id('event'))->value('list_revision');
    $from = now()->subDay()->toIso8601String();
    $until = now()->addDays(5)->toIso8601String();
    $this->actingAs($this->admin);

    $this->post('/administration/habilitations', ['holder_id' => $holder, 'event_id' => DemoSeeder::id('event'), 'zone_id' => DemoSeeder::id('zone0'), 'valid_from' => $from, 'valid_until' => $until])->assertSessionHasNoErrors();
    $this->post('/administration/acces/habilitations', ['visitor_id' => $participation, 'zone_id' => DemoSeeder::id('zone1'), 'kind' => 'standard', 'valid_from' => $from, 'valid_until' => $until])->assertSessionHasNoErrors();
    $rights = hbRights($person);
    expect($rights['permanentes'])->toHaveCount(1)->and($rights['activites'])->toHaveCount(1);
    $act = $rights['activites'][0]['id'];
    $perm = $rights['permanentes'][0]['id'];

    $this->post('/administration/acces/habilitations/'.$act.'/suspendre', ['reason' => 'Contrôle fictif'])->assertSessionHasNoErrors();
    $this->post('/administration/acces/habilitations/'.$act.'/reprendre')->assertSessionHasNoErrors();
    $this->post('/administration/acces/habilitations/'.$act.'/retirer', ['reason' => 'Fin fictive'])->assertSessionHasNoErrors();
    $this->post('/administration/habilitations/'.$perm.'/retirer', ['reason' => 'Fin fictive'])->assertSessionHasNoErrors();

    expect(hbAudit('habilitation.accordee'))->toBe($before['granted'] + 2)->and(hbAudit('habilitation.suspendue'))->toBe($before['suspended'] + 1)
        ->and(hbAudit('habilitation.reprise'))->toBe($before['resumed'] + 1)->and(hbAudit('habilitation.retiree'))->toBe($before['revoked'] + 2)
        ->and(DB::table('persons')->count())->toBe($before['persons']);
    // Garantie hors ligne : toute modification d'un droit de participation fait avancer la révision de la liste des postes (4 opérations sur ce moteur).
    expect((int) DB::table('events')->where('id', DemoSeeder::id('event'))->value('list_revision'))->toBeGreaterThanOrEqual($revision + 4);
    expect(hbRight($person, $act)['state'])->toBe('retiree')->and(hbRight($person, $perm)['state'])->toBe('retiree');
});

it('refuse côté serveur les préconditions non remplies, avec un résultat explicite', function () {
    $p = hbPerson(true, true);
    $this->actingAs($this->admin);

    $this->post('/administration/acces/habilitations/'.$p['act'].'/reprendre')->assertSessionHasErrors('id');
    $this->post('/administration/acces/habilitations/'.$p['act'].'/suspendre', ['reason' => 'Contrôle fictif'])->assertSessionHasNoErrors();
    $this->post('/administration/acces/habilitations/'.$p['act'].'/suspendre', ['reason' => 'Encore'])->assertSessionHasErrors('id');
    $this->post('/administration/acces/habilitations/'.$p['act'].'/retirer', [])->assertSessionHasErrors('reason');
    $this->post('/administration/habilitations/'.$p['perm'].'/retirer', ['reason' => 'Fin fictive'])->assertSessionHasNoErrors();
    $this->post('/administration/habilitations/'.$p['perm'].'/retirer', ['reason' => 'Encore'])->assertSessionHasErrors('reason');
    // Droit exceptionnel : motif obligatoire ; période cohérente ; attribution refusée sur une participation clôturée ou à un profil clos.
    $window = ['valid_from' => now()->toIso8601String(), 'valid_until' => now()->addHours(2)->toIso8601String()];
    $this->post('/administration/acces/habilitations', ['visitor_id' => $p['participation'], 'zone_id' => DemoSeeder::id('zone2'), 'kind' => 'exceptionnelle', ...$window])->assertSessionHasErrors('reason');
    $this->post('/administration/acces/habilitations', ['visitor_id' => $p['participation'], 'zone_id' => DemoSeeder::id('zone2'), 'kind' => 'standard', 'valid_from' => now()->toIso8601String(), 'valid_until' => now()->subDay()->toIso8601String()])->assertSessionHasErrors('valid_until');
    app(Participations::class)->close($this->admin, $p['participation'], 'Fin du stage fictif');
    $this->post('/administration/acces/habilitations', ['visitor_id' => $p['participation'], 'zone_id' => DemoSeeder::id('zone2'), 'kind' => 'standard', ...$window])->assertSessionHasErrors('visitor_id');
    app(PermanentHolders::class)->close($p['holder'], 'Fin d’engagement fictive', $this->admin);
    $this->post('/administration/habilitations', ['holder_id' => $p['holder'], 'event_id' => DemoSeeder::id('event'), 'zone_id' => DemoSeeder::id('zone2'), ...$window])->assertSessionHasErrors('holder_id');
});

// --- Permissions : aucune opération sans le droit, même par requête directe ------------------------------------------------------

it('refuse toute opération d’habilitation sans les deux permissions requises, même par requête directe', function () {
    $p = hbPerson(true, true);
    $listOnly = hbUser('agent_accueil', ['listes.importer']);
    $zonesOnly = hbUser('agent_accueil', ['zones.gerer']);
    $calls = [
        ['/administration/habilitations', ['holder_id' => $p['holder'], 'event_id' => DemoSeeder::id('event'), 'zone_id' => DemoSeeder::id('zone2'), 'valid_from' => now()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String()]],
        ['/administration/habilitations/'.$p['perm'].'/retirer', ['reason' => 'Tentative']],
        ['/administration/acces/habilitations', ['visitor_id' => $p['participation'], 'zone_id' => DemoSeeder::id('zone2'), 'kind' => 'standard', 'valid_from' => now()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String()]],
        ['/administration/acces/habilitations/'.$p['act'].'/suspendre', ['reason' => 'Tentative']],
        ['/administration/acces/habilitations/'.$p['act'].'/reprendre', []],
        ['/administration/acces/habilitations/'.$p['act'].'/retirer', ['reason' => 'Tentative']],
    ];
    $before = [DB::table('holder_entitlements')->count(), DB::table('participation_entitlements')->count(), hbAudit('habilitation.retiree'), hbAudit('habilitation.suspendue')];

    foreach ([$this->agent, $listOnly, $zonesOnly] as $user) {
        $this->actingAs($user);
        foreach ($calls as [$uri, $data]) {
            $this->post($uri, $data)->assertForbidden();
        }
    }

    expect([DB::table('holder_entitlements')->count(), DB::table('participation_entitlements')->count(), hbAudit('habilitation.retiree'), hbAudit('habilitation.suspendue')])->toBe($before)
        ->and(hbRight($p['person'], $p['perm'])['state'])->toBe('active')->and(hbRight($p['person'], $p['act'])['state'])->toBe('active');
});

// --- Anciens parcours conservés : aucun contournement, fonctions toujours servies -----------------------------------------------------

it('garde les écrans spécialisés servis aux seuls rôles autorisés, sans contournement', function () {
    $p = hbPerson(true, true);
    $listOnly = hbUser('agent_accueil', ['listes.importer']);

    foreach (['/administration/habilitations', '/administration/acces', '/administration/titulaires', '/administration/personnes/'.$p['person']] as $uri) {
        $this->actingAs($this->agent)->get($uri)->assertForbidden();
    }
    // « Accès aux zones » exige « Zones » : « Personnes et listes » seul ne suffit pas.
    $this->actingAs($listOnly)->get('/administration/acces')->assertForbidden();

    $this->actingAs($this->admin);
    $this->get('/administration/habilitations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Habilitations'));
    $this->get('/administration/acces')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/AccesZones'));
    $this->get('/administration/titulaires')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Titulaires'));
    $this->get('/administration/personnes/'.$p['person'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Personne')->where('person.id', $p['person']));
    $this->get('/administration/personnes?personne='.$p['person'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Personnes')
        ->has('selected.entitlements.permanentes', 1)->has('selected.entitlements.activites', 1)->where('can.habilitations', true));
    app('auth')->forgetGuards();
    $this->get('/administration/habilitations')->assertRedirect();
});
