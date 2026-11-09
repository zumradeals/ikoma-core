<?php

use App\Models\User;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\OperationsOverview;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use App\Services\Pilotage;
use App\Services\Presence;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Chantier Opérations — vue globale en lecture seule : les chiffres viennent des événements réels, le contexte permanent et les passages hors activité ne disparaissent pas
 * de la supervision générale, les permissions sont vérifiées côté serveur. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function opUser(string $role, array $extra = []): User
{
    $user = User::create(['name' => 'Compte fictif opérations', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $role === '' ?: $user->assignRole($role);
    foreach ($extra as $permission) {
        $user->givePermissionTo($permission);
    }
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function opMove(string $event, string $visitor, string $type, ?string $at = null): string
{
    $id = (string) Str::uuid();
    DB::table('access_events')->insert(['id' => $id, 'event_id' => $event, 'visitor_id' => $visitor, 'type' => $type, 'checkpoint' => 'accueil', 'occurred_at' => $at ?? now(), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return array{event: string, visitor: string} participant d'une activité ordinaire */
function opActivityVisitor(): array
{
    $event = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $event, 'name' => 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzoperations '.Str::lower(Str::random(6))]);

    return ['event' => $event, 'visitor' => app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $event, 'category_id' => $category])];
}

/** @return array{event: string, visitor: string} participation d'un titulaire dans le contexte permanent (carte émise) */
function opPermanentVisitor(): array
{
    app(PermanentHolders::class)->createPermanentContext(test()->admin);
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzpermanent '.Str::lower(Str::random(6))]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], test()->admin, false, $person);
    app(HolderCards::class)->issue($holder, test()->admin, true);
    $event = (string) DB::table('events')->where('is_permanent', true)->value('id');

    return ['event' => $event, 'visitor' => (string) DB::table('visitors')->where('holder_id', $holder)->where('event_id', $event)->value('id')];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = opUser('administrateur');
    $this->supervisor = opUser('superviseur');
    $this->agent = opUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
});

it('sert l’écran Opérations aux comptes qui consultent le pilotage et le refuse aux autres, côté serveur', function () {
    $this->actingAs($this->admin)->get('/administration/operations')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Operations')
        ->has('general.mouvements')->has('general.presents.total')->has('general.postes.total')->has('general.par_heure', 24)->has('events')->where('can.postes', true));
    $this->actingAs($this->supervisor)->get('/administration/operations')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.postes', false)->where('can.litiges', true));
    $this->actingAs($this->agent)->get('/administration/operations')->assertForbidden();
});

it('ne communique aucun nom de personne à un compte qui ne consulte pas les présences', function () {
    $v = opActivityVisitor();
    opMove($v['event'], $v['visitor'], 'entree');
    $only = opUser('', ['pilotage.consulter']);
    $this->actingAs($only)->get('/administration/operations?event='.$v['event'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.presences', false)->where('centre', null)->has('general'));
    $this->actingAs($this->admin)->get('/administration/operations?event='.$v['event'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.presences', true)->has('centre.movements', 1));
});

it('calcule les indicateurs sur les événements réels : entrées, sorties, contrôles, refus, présents', function () {
    $overview = app(OperationsOverview::class);
    $before = $overview->general();
    $v = opActivityVisitor();
    $in = opMove($v['event'], $v['visitor'], 'entree');
    opMove($v['event'], $v['visitor'], 'controle');
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $v['visitor'], 'reason' => 'Accès refusé (fictif)', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $after = $overview->general();
    expect($after['mouvements']['entrees'])->toBe($before['mouvements']['entrees'] + 1)->and($after['mouvements']['controles'])->toBe($before['mouvements']['controles'] + 1)
        ->and($after['mouvements']['refus'])->toBe($before['mouvements']['refus'] + 1)->and($after['litiges'])->toBe($before['litiges'] + 1)
        ->and($after['presents']['activites'])->toBe($before['presents']['activites'] + 1);
    // un contrôle intérieur n'est jamais une seconde entrée : la personne n'est comptée qu'une fois
    expect($after['presents']['total'])->toBe($before['presents']['total'] + 1);
    // une sortie la retire des présents sans effacer l'entrée
    opMove($v['event'], $v['visitor'], 'sortie', now()->addMinute()->toDateTimeString());
    $out = $overview->general();
    expect($out['presents']['activites'])->toBe($before['presents']['activites'])->and($out['mouvements']['sorties'])->toBe($before['mouvements']['sorties'] + 1)->and($out['mouvements']['entrees'])->toBe($after['mouvements']['entrees']);
    // la série horaire porte les passages de l'heure courante
    expect(array_sum(array_column($out['par_heure'], 'entrees')))->toBe($out['mouvements']['entrees'])->and($in)->not->toBeEmpty();
});

it('garde visibles dans la supervision générale les passages des titulaires permanents, sans activité ouverte', function () {
    $overview = app(OperationsOverview::class);
    $before = $overview->general();
    $p = opPermanentVisitor();
    opMove($p['event'], $p['visitor'], 'entree');
    $after = $overview->general();
    expect($after['presents']['permanents'])->toBe($before['presents']['permanents'] + 1)->and($after['presents']['total'])->toBe($before['presents']['total'] + 1)
        ->and($after['mouvements']['entrees'])->toBe($before['mouvements']['entrees'] + 1);
    // le contexte permanent ne devient jamais l'activité affichée par défaut, mais peut être consulté explicitement
    $pilotage = app(Pilotage::class);
    expect($pilotage->event(null)?->id)->not->toBe($p['event'])->and($pilotage->event($p['event'])?->id)->toBe($p['event']);
    $this->actingAs($this->admin)->get('/administration/operations?event='.$p['event'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('event.id', $p['event'])->where('counters.presents', app(Presence::class)->count($p['event']))->has('centre.presents.rows'));
    // le Pilotage du superviseur affiche aussi le site entier, quelle que soit l'activité consultée
    $this->actingAs($this->supervisor)->get('/pilotage')->assertOk()->assertInertia(fn (Assert $page) => $page->where('site.presents.permanents', $after['presents']['permanents']));
});

it('ne modifie rien : la vue Opérations ne fait que lire', function () {
    $v = opActivityVisitor();
    opMove($v['event'], $v['visitor'], 'entree');
    $tables = ['access_events', 'visitors', 'badges', 'alerts', 'refusals', 'participation_entitlements', 'holder_entitlements'];
    $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
    $before = $counts();
    $audit = DB::table('audit_logs')->count();
    $this->actingAs($this->admin)->get('/administration/operations?event='.$v['event'])->assertOk();
    $this->get('/administration/operations')->assertOk();
    expect($counts())->toBe($before)->and(DB::table('audit_logs')->count())->toBe($audit);
});
