<?php

use App\Models\User;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\ParticipationEntitlements;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\PersonRegistry;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * V1 / lot P1 — registre unifié des personnes et fiche en panneau : LECTURE SEULE. Données fictives uniquement.
 * Le registre compose les identités (`persons`), le profil permanent, les participations et les deux moteurs d'habilitations,
 * sans rien créer ni modifier, et sans fondre les catégories de participation dans un statut institutionnel.
 */

uses(DatabaseTransactions::class);

function prgUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif registre', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{0: string, 1: string} [identifiant du profil permanent, identifiant de la personne] */
function prgHolder(string $name, string $category = 'AILCT-01'): array
{
    $holder = app(PermanentHolders::class)->create(['full_name' => $name, 'organisation' => 'Organisation fictive', 'position' => 'Formateur fictif', 'institutional_category' => $category], test()->admin);

    return [$holder, (string) DB::table('permanent_holders')->where('id', $holder)->value('person_id')];
}

function prgName(): string
{
    return 'Zzregistre '.Str::lower(Str::random(8));
}

function prgRow(string $personId, string $name): object
{
    foreach (app(PersonRegistry::class)->list(['q' => $name])->items() as $row) {
        if ($row->id === $personId) {
            return $row;
        }
    }
    throw new RuntimeException('Personne absente du registre.');
}

function prgGrant(string $kind, string $target, string $zone, string $from = '-1 day', string $until = '+10 days'): string
{
    $start = now()->modify($from)->toIso8601String();
    $end = now()->modify($until)->toIso8601String();

    return $kind === 'permanente'
        ? app(HolderEntitlements::class)->grant($target, DemoSeeder::id('event'), $zone, $start, $end, test()->admin)
        : app(ParticipationEntitlements::class)->grant($target, $zone, 'standard', null, $start, $end, test()->admin);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = prgUser('administrateur');
    $this->agent = prgUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->actingAs($this->admin);
});

it('ne crée ni ne modifie aucune donnée en consultant le registre, ses filtres et une fiche', function () {
    $name = prgName();
    [$holder, $person] = prgHolder($name);
    $tables = ['persons', 'visitors', 'permanent_holders', 'participation_entitlements', 'holder_entitlements', 'badge_assignments', 'audit_logs'];
    $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

    $this->get('/administration/personnes')->assertOk();
    $this->get('/administration/personnes?'.http_build_query(['q' => $name, 'institutionnel' => 'AILCT-01', 'habilitations' => 'sans', 'statut' => 'actif']))->assertOk();
    $this->get('/administration/personnes?personne='.$person)->assertOk();

    expect(array_map(fn (string $t): int => DB::table($t)->count(), $tables))->toBe($before);
});

it('distingue le statut institutionnel des catégories de participation et n’attribue jamais la catégorie d’une participation à la personne', function () {
    $name = prgName();
    [, $holderPerson] = prgHolder($name);
    $participantPerson = (string) DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('person_id');
    $participantName = (string) DB::table('persons')->where('id', $participantPerson)->value('full_name');

    $permanent = prgRow($holderPerson, $name);
    expect($permanent->institutional_category)->toBe('AILCT-01')->and($permanent->category_label)->not->toBeNull();

    $participant = prgRow($participantPerson, $participantName);
    expect($participant->institutional_category)->toBeNull()->and($participant->category_label)->toBeNull()->and((int) $participant->participations_actives)->toBeGreaterThanOrEqual(1);
    foreach ([$permanent, $participant] as $row) {
        expect(array_keys((array) $row))->not->toContain('category')->and(array_keys((array) $row))->not->toContain('categories');
    }
});

it('compte et présente séparément les habilitations permanentes et celles liées à une activité, avec leur origine', function () {
    $name = prgName();
    [$holder, $person] = prgHolder($name);
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
    prgGrant('permanente', $holder, DemoSeeder::id('zone0'));
    prgGrant('activite', $participation, DemoSeeder::id('zone1'));

    expect((int) prgRow($person, $name)->habilitations_actives)->toBe(2)->and((int) prgRow($person, $name)->participations_actives)->toBe(1);

    $profile = app(PersonRegistry::class)->profile($person);
    expect($profile['entitlements']['permanentes'])->toHaveCount(1)->and($profile['entitlements']['activites'])->toHaveCount(1)
        ->and($profile['entitlements']['permanentes'][0]['origin'])->toBe('permanente')->and($profile['entitlements']['activites'][0]['origin'])->toBe('activite')
        ->and($profile['entitlements']['permanentes'][0]['state'])->toBe('active')->and($profile['entitlements']['activites'][0]['state'])->toBe('active')
        ->and($profile['access']['effective'])->toBe(2)->and($profile['access']['zones'])->toHaveCount(2)
        ->and($profile['holder']['institutional_category'])->toBe('AILCT-01')->and($profile['holder']['identifier'])->not->toBeNull();
});

it('retire seulement les droits de la participation clôturée et laisse l’habilitation permanente', function () {
    $name = prgName();
    [$holder, $person] = prgHolder($name);
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
    prgGrant('permanente', $holder, DemoSeeder::id('zone0'));
    prgGrant('activite', $participation, DemoSeeder::id('zone1'));

    app(Participations::class)->close($this->admin, $participation, 'Fin du stage fictif');

    $profile = app(PersonRegistry::class)->profile($person);
    expect($profile['entitlements']['permanentes'][0]['state'])->toBe('active')->and($profile['entitlements']['activites'][0]['state'])->toBe('participation_close')
        ->and((int) prgRow($person, $name)->habilitations_actives)->toBe(1)->and($profile['access']['effective'])->toBe(1);
    // l'identité, le profil permanent et la participation restent consultables
    expect($profile['person']->status)->toBe('actif')->and($profile['participations'])->toHaveCount(1);
});

it('expire les droits à leur échéance, sans rien supprimer, et les compte parmi les droits terminés', function () {
    $name = prgName();
    [$holder, $person] = prgHolder($name);
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
    prgGrant('permanente', $holder, DemoSeeder::id('zone0'), '-1 day', '+5 days');
    prgGrant('activite', $participation, DemoSeeder::id('zone1'), '-1 day', '+5 days');
    $before = app(PersonRegistry::class)->kpis();
    expect((int) prgRow($person, $name)->habilitations_actives)->toBe(2);

    $this->travelTo('2027-01-20 10:00:00');

    $after = app(PersonRegistry::class)->kpis();
    $states = array_column(app(PersonRegistry::class)->profile($person)['entitlements']['permanentes'], 'state');
    expect((int) prgRow($person, $name)->habilitations_actives)->toBe(0)->and($states)->toBe(['expiree'])
        ->and($after['habilitations_actives'])->toBeLessThanOrEqual($before['habilitations_actives'] - 2)->and($after['retirees_ou_expirees'])->toBeGreaterThanOrEqual($before['retirees_ou_expirees'] + 2)
        ->and(DB::table('holder_entitlements')->where('holder_id', $holder)->count())->toBe(1);
});

it('signale les droits qui arrivent à échéance dans la fenêtre annoncée et les distingue des droits plus lointains', function () {
    $name = prgName();
    [$holder] = prgHolder($name);
    $base = app(PersonRegistry::class)->kpis();
    prgGrant('permanente', $holder, DemoSeeder::id('zone0'), '-1 day', '+10 days');
    prgGrant('permanente', $holder, DemoSeeder::id('zone1'), '-1 day', '+90 days');

    $kpis = app(PersonRegistry::class)->kpis();
    expect($kpis['habilitations_actives'])->toBe($base['habilitations_actives'] + 2)->and($kpis['a_echeance'])->toBe($base['a_echeance'] + 1)->and($kpis['jours_echeance'])->toBe(30);
});

it('filtre par statut institutionnel, présence d’habilitation et recherche sur le nom ou l’identifiant', function () {
    $name = prgName();
    [$holder, $person] = prgHolder($name);
    $registry = app(PersonRegistry::class);
    $ids = fn (array $f): array => array_map(fn ($r) => $r->id, $registry->list($f)->items());

    expect($ids(['q' => $name]))->toBe([$person])->and($ids(['q' => $name, 'institutionnel' => 'AILCT-01']))->toBe([$person])->and($ids(['q' => $name, 'institutionnel' => 'aucun']))->toBe([]);
    expect($ids(['q' => $name, 'habilitations' => 'avec']))->toBe([])->and($ids(['q' => $name, 'habilitations' => 'sans']))->toBe([$person]);
    prgGrant('permanente', $holder, DemoSeeder::id('zone0'));
    expect($ids(['q' => $name, 'habilitations' => 'avec']))->toBe([$person]);
    $identifier = (string) DB::table('permanent_holders')->where('id', $holder)->value('identifier');
    expect($ids(['q' => $identifier]))->toContain($person);
    DB::table('persons')->where('id', $person)->update(['status' => 'archive']);
    expect($ids(['q' => $name, 'statut' => 'actif']))->toBe([])->and($ids(['q' => $name, 'statut' => 'archive']))->toBe([$person]);
});

it('pagine le registre', function () {
    expect(PersonRegistry::PER_PAGE)->toBe(25);
    $page = app(PersonRegistry::class)->list([]);
    expect(count($page->items()))->toBeLessThanOrEqual(25)->and($page->total())->toBe(DB::table('persons')->count());
});

it('n’invente aucune donnée : la fiche d’une personne sans profil permanent n’a ni identifiant ni statut institutionnel', function () {
    $person = (string) DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('person_id');
    $profile = app(PersonRegistry::class)->profile($person);
    expect($profile['holder'])->toBeNull()->and(array_keys((array) $profile['person']))->not->toContain('phone')->and(array_keys((array) $profile['person']))->not->toContain('photo')
        ->and($profile['timeline'])->not->toBeEmpty()->and(app(PersonRegistry::class)->profile((string) Str::uuid()))->toBeNull();
});

it('sert la page, la fiche en rechargement partiel et refuse les filtres inconnus', function () {
    $name = prgName();
    [, $person] = prgHolder($name);

    $this->get('/administration/personnes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Personnes')->has('persons')->has('meta')->has('kpis')->where('selected', null)->where('can.habilitations', true));
    $this->get('/administration/personnes?personne='.$person)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Personnes')->where('selected.person.id', $person)->has('selected.timeline'));
    $this->get('/administration/personnes?institutionnel=ZZZ')->assertSessionHasErrors('institutionnel');
    $this->get('/administration/personnes?personne=pas-un-identifiant')->assertSessionHasErrors('personne');
});

it('réserve le registre et la fiche à la permission « Personnes et listes »', function () {
    $name = prgName();
    [, $person] = prgHolder($name);
    $this->actingAs($this->agent);
    $this->get('/administration/personnes')->assertForbidden();
    $this->get('/administration/personnes?personne='.$person)->assertForbidden();
});
