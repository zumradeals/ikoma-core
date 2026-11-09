<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\AdminOverview;
use App\Services\BadgeIssuance;
use App\Services\BadgeService;
use App\Services\CardLifecycle;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\OperationsOverview;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\PersonRegistry;
use App\Services\Persons;
use App\Services\SupportPrinting;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Tableau de bord général de l'administrateur : vue institutionnelle en lecture seule. Cohérence des compteurs avec les services canoniques, distinctions (personne ≠ habilitation ≠ présence ;
 * badge émis ≠ imprimé ≠ remis ≠ actif), priorités avec statut réel et lien, permissions serveur, aucune écriture. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function aoUser(string $role, array $extra = []): User
{
    $user = User::create(['name' => 'Compte fictif tableau', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $role === '' ?: $user->assignRole($role);
    foreach ($extra as $permission) {
        $user->givePermissionTo($permission);
    }
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{event: string, category: string, visitor: string, person: string} participant d'une activité en cours */
function aoActivity(): array
{
    $event = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $event, 'name' => 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zztableau '.Str::lower(Str::random(6))]);

    return ['event' => $event, 'category' => $category, 'person' => $person, 'visitor' => app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $event, 'category_id' => $category])];
}

/** @return array{holder: string, badge: string, card: string} carte permanente émise (non imprimée, non remise) */
function aoCard(): array
{
    app(PermanentHolders::class)->createPermanentContext(test()->admin);
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzcarte '.Str::lower(Str::random(6))]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], test()->admin, false, $person);
    DB::table('permanent_holders')->where('id', $holder)->update(['photo_file' => 'portraits/fictif-'.Str::random(6).'.jpg']);
    $r = app(BadgeIssuance::class)->issueCard($holder, (string) Str::uuid(), test()->admin);

    return ['holder' => $holder, 'badge' => $r['badge_id'], 'card' => (string) DB::table('holder_cards')->where('badge_id', $r['badge_id'])->value('id')];
}

function aoGeneral(): array
{
    return app(AdminOverview::class)->build(test()->admin);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = aoUser('administrateur');
    $this->agent = aoUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->actingAs($this->admin);
});

it('sert le tableau général à l’administrateur, et le refuse aux autres', function () {
    $this->get('/administration')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Tableau')->has('general.indicateurs.presents')->has('general.priorites')->has('general.activites')
        ->has('general.badges.cartes')->has('general.personnes.verifications')->has('general.operations.derniers')->has('steps')->has('tiles'));
    $this->actingAs($this->agent)->get('/administration')->assertForbidden();
});

it('garde les compteurs cohérents avec les services canoniques', function () {
    $g = aoGeneral();
    $kpis = app(PersonRegistry::class)->kpis();
    expect($g['indicateurs']['personnes']['enregistrees'])->toBe((int) $kpis['personnes'])->and($g['indicateurs']['habilitations']['actives'])->toBe((int) $kpis['habilitations_actives'])
        ->and($g['indicateurs']['habilitations']['a_echeance'])->toBe((int) $kpis['a_echeance'])->and($g['indicateurs']['presents']['total'])->toBe(app(OperationsOverview::class)->general()['presents']['total'])
        ->and($g['personnes']['retirees_ou_expirees'])->toBe((int) $kpis['retirees_ou_expirees']);
});

it('ne confond pas une personne enregistrée avec une habilitation active ni avec une personne présente', function () {
    $before = aoGeneral();
    app(Persons::class)->create($this->admin, ['full_name' => 'Zzsansdroit '.Str::lower(Str::random(6))]);
    $after = aoGeneral();
    expect($after['indicateurs']['personnes']['enregistrees'])->toBe($before['indicateurs']['personnes']['enregistrees'] + 1)
        ->and($after['indicateurs']['habilitations']['actives'])->toBe($before['indicateurs']['habilitations']['actives'])->and($after['indicateurs']['presents']['total'])->toBe($before['indicateurs']['presents']['total']);
});

it('distingue le support émis, imprimé, remis et actif pour une carte permanente', function () {
    $before = aoGeneral()['badges'];
    $c = aoCard();
    $issued = aoGeneral()['badges'];
    expect($issued['cartes']['actives'])->toBe($before['cartes']['actives'] + 1)->and($issued['cartes']['non_remises'])->toBe($before['cartes']['non_remises'] + 1)->and($issued['cartes']['remises'])->toBe($before['cartes']['remises'])
        ->and($issued['impression']['emis_non_imprimes'])->toBe($before['impression']['emis_non_imprimes'] + 1);
    // imprimé : n'est plus « à imprimer », mais reste NON remis et non actif
    app(SupportPrinting::class)->print($this->admin, [$c['badge']], null);
    $printed = aoGeneral()['badges'];
    expect($printed['impression']['emis_non_imprimes'])->toBe($before['impression']['emis_non_imprimes'])->and($printed['cartes']['non_remises'])->toBe($before['cartes']['non_remises'] + 1)
        ->and($printed['cartes']['imprimees_non_remises'])->toBe($before['cartes']['imprimees_non_remises'] + 1)->and($printed['cartes']['remises'])->toBe($before['cartes']['remises']);
    // remise explicite : active
    app(HolderCards::class)->activate($c['holder'], $this->admin);
    $given = aoGeneral()['badges'];
    expect($given['cartes']['remises'])->toBe($before['cartes']['remises'] + 1)->and($given['cartes']['non_remises'])->toBe($before['cartes']['non_remises'])->and($given['cartes']['imprimees_non_remises'])->toBe($before['cartes']['imprimees_non_remises']);
    // révoquée : retirée, plus active
    app(CardLifecycle::class)->revoke($c['holder'], 'perdue', null, $this->admin);
    $revoked = aoGeneral()['badges'];
    expect($revoked['cartes']['retirees'])->toBe($before['cartes']['retirees'] + 1)->and($revoked['cartes']['actives'])->toBe($before['cartes']['actives']);
});

it('compte les badges temporaires émis non imprimés, associés et non restitués, sans confondre sortie et restitution', function () {
    $before = aoGeneral()['badges'];
    $a = aoActivity();
    $r = app(BadgeIssuance::class)->issueTemporary($a['visitor'], (string) Str::uuid(), $this->admin);
    expect(aoGeneral()['badges']['impression']['emis_non_imprimes'])->toBe($before['impression']['emis_non_imprimes'] + 1);
    app(BadgeService::class)->associer($r['badge_id'], $a['visitor'], $this->agent);
    $assoc = aoGeneral()['badges'];
    expect($assoc['temporaires']['associes'])->toBe($before['temporaires']['associes'] + 1)->and($assoc['temporaires']['non_restitues'])->toBe($before['temporaires']['non_restitues']);
    // la personne est sortie : le badge reste à récupérer (une sortie ne vaut pas restitution)
    DB::table('visitors')->where('id', $a['visitor'])->update(['status' => 'sorti']);
    $out = aoGeneral();
    expect($out['badges']['temporaires']['non_restitues'])->toBe($before['temporaires']['non_restitues'] + 1)->and(collect($out['priorites'])->firstWhere('key', 'badges_non_restitues')['href'])->toBe('/administration/badges/registre');
    expect(Badge::findOrFail($r['badge_id'])->status->value)->toBe('Associé');
});

it('présente les activités ouvertes avec participants, attendus et arrivées confirmées', function () {
    $a = aoActivity();
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $a['event'], 'visitor_id' => $a['visitor'], 'type' => 'entree', 'checkpoint' => 'accueil', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $row = collect(aoGeneral()['activites'])->firstWhere('id', $a['event']);
    expect($row)->not->toBeNull()->and($row['phase'])->toBe('en_cours')->and($row['participants'])->toBe(1)->and($row['attendus'])->toBe(1)->and($row['arrivees'])->toBe(1)->and($row['presents'])->toBe(1);
});

it('donne à chaque priorité un état réel et un lien vers sa fonction d’origine, sans lien quand le compte n’y a pas droit', function () {
    $a = aoActivity();
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $a['visitor'], 'reason' => 'Accès refusé (fictif)', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $silent = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $silent, 'event_id' => $a['event'], 'name' => 'Tablette fictive', 'station' => 'Poste fictif', 'point_code' => 'poste_2', 'enrolled_at' => now(), 'token_hash' => hash('sha256', Str::random(16)), 'last_synced_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);
    $byKey = collect(aoGeneral()['priorites'])->keyBy('key');
    // le litige relève du superviseur : l'administrateur voit l'état mais pas de lien de décision
    expect($byKey['litiges']['count'])->toBeGreaterThanOrEqual(1)->and($byKey['litiges']['status'])->toBe('En attente de décision')->and($byKey['litiges']['href'])->toBeNull()
        ->and($byKey['synchronisation']['count'])->toBeGreaterThanOrEqual(1)->and($byKey['synchronisation']['status'])->toBe('Silencieux')->and($byKey['synchronisation']['href'])->toBe('/administration/postes');
    foreach ($byKey as $p) {
        expect($p['status'])->not->toBe('')->and($p['count'])->toBeGreaterThan(0)->and(in_array($p['severity'], ['haute', 'normale', 'info'], true))->toBeTrue();
    }
});

it('ne communique les noms des derniers mouvements qu’aux comptes qui consultent les présences', function () {
    $a = aoActivity();
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $a['event'], 'visitor_id' => $a['visitor'], 'type' => 'entree', 'checkpoint' => 'accueil', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $limited = aoUser('', ['evenements.gerer']);
    $this->actingAs($limited)->get('/administration')->assertOk()->assertInertia(fn (Assert $page) => $page->where('general.operations.derniers.0.name', null)->where('general.operations.derniers.0.type', 'entree'));
    $this->actingAs($this->admin)->get('/administration')->assertOk()->assertInertia(fn (Assert $page) => $page->where('general.operations.derniers.0.name', fn ($n) => str_contains((string) $n, 'Zztableau')));
});

it('ne fait que lire : aucune écriture ni entrée de journal à l’affichage', function () {
    aoActivity();
    $tables = ['persons', 'visitors', 'badges', 'badge_issuances', 'holder_cards', 'access_events', 'alerts', 'refusals', 'participation_entitlements', 'holder_entitlements'];
    $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
    $before = $counts();
    $audit = DB::table('audit_logs')->count();
    $this->get('/administration')->assertOk();
    expect($counts())->toBe($before)->and(DB::table('audit_logs')->count())->toBe($audit);
});

it('fournit les données du tableau de la maquette : titulaires, accès par heure, refus récents, véhicules et dernière synchronisation', function () {
    $a = aoActivity();
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $a['event'], 'visitor_id' => $a['visitor'], 'type' => 'entree', 'checkpoint' => 'accueil', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $a['visitor'], 'reason' => 'Refus fictif', 'refused_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
    $g = aoGeneral();
    $parHeure = $g['operations']['par_heure'];
    expect($g['indicateurs']['titulaires']['actifs'])->toBe((int) DB::table('permanent_holders')->where('status', 'actif')->count())
        ->and($parHeure)->toHaveCount(24)->and(array_sum(array_column($parHeure, 'entrees')))->toBe($g['operations']['mouvements']['entrees'])
        ->and($g['operations']['vehicules_presents'])->toBe((int) DB::table('vehicles')->where('status', 'present')->count())
        ->and($g['operations']['derniere_synchro'])->toBe(DB::table('devices')->whereNotNull('token_hash')->whereNull('revoked_at')->max('last_synced_at'));
    $derniers = $g['operations']['derniers'];
    expect($derniers[0]['type'])->toBe('refus')->and($derniers[0]['resultat'])->toBe('refuse')->and($derniers[0]['name'])->toContain('Zztableau')
        ->and(collect($derniers)->firstWhere('type', 'entree')['resultat'])->toBe('autorise');
});
