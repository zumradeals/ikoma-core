<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\ActivityRegistry;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\ParticipationEntitlements;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * V1 / chantier Activités — registre et fiche d'activité. L'écran COMPOSE les services existants (inscription, clôture de participation, clôture d'activité) :
 * ces tests garantissent, côté serveur, l'indépendance des activités, la réutilisation des identités, la séparation des habilitations permanentes et d'activité,
 * la préservation des badges, les permissions par rôle, la traçabilité et l'absence d'effet sur la présence. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function actUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif activités', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{id: string, category: string} une activité ouverte avec une catégorie de participation */
function actEvent(string $kind = 'stage', ?string $status = null, string $name = ''): array
{
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => $name !== '' ? $name : 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => $kind, 'status' => $status, ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    DB::table('zones')->insert(['id' => (string) Str::uuid(), 'event_id' => $id, 'name' => 'Salle fictive', ...$at]);

    return ['id' => $id, 'category' => $category];
}

function actPerson(string $name): string
{
    return app(Persons::class)->create(test()->admin, ['full_name' => $name.' '.Str::lower(Str::random(6))]);
}

function actAudit(string $action): int
{
    return DB::table('audit_logs')->where('action', $action)->count();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = actUser('administrateur');
    $this->agent = actUser('agent_accueil');
    $this->stage1 = actEvent('stage', null, 'Stage fictif un');
    $this->stage2 = actEvent('evenement', null, 'Événement fictif deux');
});

// --- 1. Création et consultation selon les permissions -----------------------------------------------------------------------------

it('sert le registre aux seuls comptes autorisés et expose indicateurs, activités et droits d’affichage', function () {
    $this->actingAs($this->admin)->get('/administration/evenements')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Evenements')
        ->has('events')->has('kpis.en_cours')->has('kpis.participants_actifs')->has('kpis.a_venir')->has('kpis.cloturees')
        ->where('can.participants', true)->where('can.journal', true)->where('selected', null));
    $this->actingAs($this->agent)->get('/administration/evenements')->assertForbidden();
    $this->post('/administration/evenements', ['name' => 'Interdit', 'starts_at' => '2027-02-01 08:00', 'ends_at' => '2027-02-02 08:00'])->assertForbidden();
});

it('crée une activité par la route existante et la retrouve dans le registre avec sa nature', function () {
    $this->actingAs($this->admin)->post('/administration/evenements', ['name' => 'Formation fictive des formateurs', 'kind' => 'stage', 'site' => 'Salle 1', 'starts_at' => '2027-02-01 08:00', 'ends_at' => '2027-02-05 18:00'])->assertRedirect();
    $row = collect(app(ActivityRegistry::class)->rows(false))->firstWhere('name', 'Formation fictive des formateurs');
    expect($row)->not->toBeNull()->and($row['kind'])->toBe('stage')->and($row['phase'])->toBe('a_venir')->and((int) $row['participants'])->toBe(0);
    expect(actAudit('evenement.cree'))->toBeGreaterThan(0);
});

it('dérive la phase des dates et du statut enregistré, sans la stocker, et calcule les indicateurs sur les données réelles', function () {
    $closed = actEvent('stage', 'clos');
    $rows = collect(app(ActivityRegistry::class)->rows(false))->keyBy('id');
    expect($rows[$this->stage1['id']]['phase'])->toBe('en_cours')->and($rows[$closed['id']]['phase'])->toBe('cloturee');
    $before = app(ActivityRegistry::class)->kpis();
    $p = actPerson('Zzkpi');
    app(Participations::class)->enrol($this->admin, $p, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $after = app(ActivityRegistry::class)->kpis();
    expect($after['participants_actifs'])->toBe($before['participants_actifs'] + 1)->and($after['cloturees'])->toBeGreaterThanOrEqual(1);
});

// --- 2 à 5. Indépendance, réutilisation d'identité, absence de doublon, catégories --------------------------------------------------

it('inscrit une personne existante par la route existante, revient à la fiche de l’activité et ne crée aucune identité', function () {
    $person = actPerson('Zzreutilisee');
    $persons = DB::table('persons')->count();
    $this->actingAs($this->admin)->post("/administration/personnes/{$person}/participations", ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category'], 'retour' => 'activites'])
        ->assertRedirect('/administration/evenements?activite='.$this->stage1['id'].'&onglet=participants');
    $this->post("/administration/personnes/{$person}/participations", ['event_id' => $this->stage2['id'], 'category_id' => $this->stage2['category'], 'retour' => 'activites'])->assertRedirect();
    expect(DB::table('persons')->count())->toBe($persons)
        ->and(DB::table('visitors')->where('person_id', $person)->count())->toBe(2);
    // pas de doublon : une seconde inscription à la même activité est refusée par le service
    $this->post("/administration/personnes/{$person}/participations", ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category'], 'retour' => 'activites'])->assertSessionHasErrors('event_id');
    expect(DB::table('visitors')->where('person_id', $person)->where('event_id', $this->stage1['id'])->count())->toBe(1);
});

it('n’accepte jamais une adresse de retour libre : seule la valeur « activites » est admise', function () {
    $person = actPerson('Zzretour');
    $this->actingAs($this->admin)->post("/administration/personnes/{$person}/participations", ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category'], 'retour' => 'https://exemple.test/hameçon'])
        ->assertSessionHasErrors('retour');
    expect(DB::table('visitors')->where('person_id', $person)->count())->toBe(0);
});

it('garde la catégorie de participation propre à l’activité et sans effet sur la personne', function () {
    $person = actPerson('Zzcategorie');
    app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $list = app(ActivityRegistry::class)->participants($this->stage1['id'], null, 1);
    expect($list['data'])->toHaveCount(1)->and($list['data'][0]['category'])->toBe('Stagiaire')->and($list['data'][0]['status'])->toBe('attendu');
    // la personne n'a pris aucun profil ni catégorie permanents
    expect(DB::table('permanent_holders')->where('person_id', $person)->exists())->toBeFalse();
});

it('refuse l’inscription à une activité clôturée et ne propose alors aucun formulaire d’inscription', function () {
    $closed = actEvent('stage', 'clos');
    $person = actPerson('Zzclos');
    $this->actingAs($this->admin)->post("/administration/personnes/{$person}/participations", ['event_id' => $closed['id'], 'category_id' => $closed['category'], 'retour' => 'activites'])->assertSessionHasErrors('event_id');
    $profile = app(ActivityRegistry::class)->profile($closed['id'], true);
    expect($profile['enrol'])->toBeNull()->and($profile['event']['closed'])->toBeTrue();
    expect(app(ActivityRegistry::class)->profile($this->stage1['id'], true)['enrol']['categories'])->toHaveCount(1);
});

// --- Fiche et participants (consultation) ---------------------------------------------------------------------------------------------------

it('présente la fiche d’une activité et ses participants, avec recherche', function () {
    $a = actPerson('Zzalpha');
    $b = actPerson('Zzbeta');
    app(Participations::class)->enrol($this->admin, $a, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    app(Participations::class)->enrol($this->admin, $b, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $this->actingAs($this->admin)->get('/administration/evenements?activite='.$this->stage1['id'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selected.event.id', $this->stage1['id'])->where('selected.counts.participants', 2)->where('selected.counts.actifs', 2)
        ->has('participants.data', 2)->where('participants.meta.total', 2));
    $this->get('/administration/evenements?activite='.$this->stage1['id'].'&q=zzalpha')->assertInertia(fn (Assert $page) => $page->has('participants.data', 1)->where('participants.data.0.person_id', $a));
    // le terme de recherche n'est jamais interprété comme un motif SQL
    $this->get('/administration/evenements?activite='.$this->stage1['id'].'&q=%25')->assertInertia(fn (Assert $page) => $page->has('participants.data', 0));
});

it('ne communique les participants qu’aux comptes qui gèrent les listes', function () {
    $manager = actUser('superviseur');
    $manager->givePermissionTo('evenements.gerer');
    $this->actingAs($manager)->get('/administration/evenements?activite='.$this->stage1['id'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.participants', false)->where('participants', null)->where('selected.event.id', $this->stage1['id']));
});

// --- Indépendance des activités et clôture d'une participation ----------------------------------------------------------------------

it('clôture une participation sans toucher aux autres participations de la personne ni à la personne', function () {
    $person = actPerson('Zzindependante');
    $one = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $two = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage2['id'], 'category_id' => $this->stage2['category']]);
    $this->actingAs($this->admin)->post("/administration/participations/{$one}/cloturer", ['reason' => 'Fin du stage', 'retour' => 'activites'])
        ->assertRedirect('/administration/evenements?activite='.$this->stage1['id'].'&onglet=participants');
    expect(DB::table('visitors')->where('id', $one)->value('closed_at'))->not->toBeNull()
        ->and(DB::table('visitors')->where('id', $two)->value('closed_at'))->toBeNull()
        ->and(DB::table('persons')->where('id', $person)->value('status'))->toBe('actif');
    $rows = collect(app(ActivityRegistry::class)->rows(false))->keyBy('id');
    expect((int) $rows[$this->stage1['id']]['participants_actifs'])->toBe(0)->and((int) $rows[$this->stage2['id']]['participants_actifs'])->toBe(1);
    // la clôture d'une participation ne clôt pas l'activité
    expect(DB::table('events')->where('id', $this->stage1['id'])->value('status'))->not->toBe('clos');
});

it('journalise l’inscription et la clôture, et les montre dans l’historique de l’activité aux comptes qui consultent le journal', function () {
    $person = actPerson('Zztrace');
    $created = actAudit('participation.creee');
    $closed = actAudit('participation.cloturee');
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    app(Participations::class)->close($this->admin, $participation, 'Fin du stage');
    expect(actAudit('participation.creee'))->toBe($created + 1)->and(actAudit('participation.cloturee'))->toBe($closed + 1);
    $actions = array_column(app(ActivityRegistry::class)->profile($this->stage1['id'], true)['journal'], 'action');
    expect($actions)->toContain('participation.creee')->and($actions)->toContain('participation.cloturee');
    expect(app(ActivityRegistry::class)->profile($this->stage1['id'], false)['journal'])->toBe([]);
});

it('refuse la clôture à un compte sans droit de liste et ne la permet qu’aux comptes habilités, par requête directe', function () {
    $person = actPerson('Zzdroits');
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $this->actingAs($this->agent)->post("/administration/participations/{$participation}/cloturer", ['reason' => 'Tentative'])->assertForbidden();
    $this->actingAs($this->agent)->post("/administration/personnes/{$person}/participations", ['event_id' => $this->stage2['id'], 'category_id' => $this->stage2['category']])->assertForbidden();
    expect(DB::table('visitors')->where('id', $participation)->value('closed_at'))->toBeNull();
    // clôture de l'activité : réservée à « evenements.gerer »
    $this->actingAs($this->agent)->post("/administration/evenements/{$this->stage1['id']}/cloturer", ['confirmation' => true])->assertForbidden();
    // le motif reste obligatoire
    $this->actingAs($this->admin)->post("/administration/participations/{$participation}/cloturer", ['reason' => ''])->assertSessionHasErrors('reason');
});

// --- Présence, badges --------------------------------------------------------------------------------------------------------------------------

it('ne clôture jamais une participation dont la personne est présente et ne modifie pas la présence', function () {
    $person = actPerson('Zzpresente');
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    DB::table('visitors')->where('id', $participation)->update(['status' => 'present']);
    $this->actingAs($this->admin)->post("/administration/participations/{$participation}/cloturer", ['reason' => 'Fin du stage'])->assertSessionHasErrors('participation');
    expect(DB::table('visitors')->where('id', $participation)->value('status'))->toBe('present')->and(DB::table('visitors')->where('id', $participation)->value('closed_at'))->toBeNull();
    expect(app(ActivityRegistry::class)->profile($this->stage1['id'], false)['counts']['presents'])->toBe(1);
    $row = app(ActivityRegistry::class)->participants($this->stage1['id'], null, 1)['data'][0];
    expect($row['status'])->toBe('present');
});

it('préserve les badges : une clôture est refusée tant qu’un badge est attribué, et celui d’une autre participation reste intact', function () {
    $person = actPerson('Zzbadge');
    $one = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage1['id'], 'category_id' => $this->stage1['category']]);
    $two = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $this->stage2['id'], 'category_id' => $this->stage2['category']]);
    $batch = app(BadgeLots::class)->generate($this->stage2['id'], $this->stage2['category'], 2, $this->admin);
    $badge = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->first();
    app(BadgeService::class)->associer($badge, $two, $this->agent);
    // clôturer la participation 1 ne libère pas le badge nécessaire à la participation 2
    $this->actingAs($this->admin)->post("/administration/participations/{$one}/cloturer", ['reason' => 'Fin du stage'])->assertRedirect();
    expect(DB::table('badge_assignments')->where('visitor_id', $two)->whereNull('returned_at')->whereNull('cancelled_at')->count())->toBe(1)
        ->and(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // la participation qui détient un badge ne peut pas être clôturée : la clôture ne vaut jamais restitution
    $this->post("/administration/participations/{$two}/cloturer", ['reason' => 'Fin'])->assertSessionHasErrors('participation');
    $row = app(ActivityRegistry::class)->participants($this->stage2['id'], null, 1)['data'][0];
    expect($row['badge'])->toBe($badge->code)->and(app(ActivityRegistry::class)->profile($this->stage2['id'], false)['counts']['badges_attribues'])->toBe(1);
});

// --- Habilitations : permanent et activité restent séparés -----------------------------------------------------------------------------

it('sépare les habilitations d’activité du permanent : la clôture d’une participation ou de l’activité ne retire jamais un droit permanent', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $person = actPerson('Zzseparation');
    $from = now()->subDay()->toIso8601String();
    $until = now()->addDays(20)->toIso8601String();
    $participation = app(Participations::class)->enrol($this->admin, $person, ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0')]);
    app(ParticipationEntitlements::class)->grant($participation, DemoSeeder::id('zone1'), 'standard', null, $from, $until, $this->admin);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], $this->admin, false, $person);
    $permanent = app(HolderEntitlements::class)->grant($holder, DemoSeeder::id('event'), DemoSeeder::id('zone0'), $from, $until, $this->admin);

    $before = app(ActivityRegistry::class)->profile(DemoSeeder::id('event'), false);
    expect($before['counts']['habilitations'])->toBeGreaterThanOrEqual(1);
    $row = collect(app(ActivityRegistry::class)->participants(DemoSeeder::id('event'), 'zzseparation', 1)['data'])->firstWhere('id', $participation);
    expect($row['habilitations'])->toBe(1);

    $this->actingAs($this->admin)->post("/administration/participations/{$participation}/cloturer", ['reason' => 'Fin du stage', 'retour' => 'activites'])->assertRedirect();
    expect(DB::table('holder_entitlements')->where('id', $permanent)->value('revoked_at'))->toBeNull()
        ->and(DB::table('permanent_holders')->where('id', $holder)->value('status'))->toBe('actif');
    // l'habilitation de la participation clôturée n'est plus effective ; le permanent n'est jamais compté dans l'activité
    $after = app(ActivityRegistry::class)->profile(DemoSeeder::id('event'), false);
    expect($after['counts']['habilitations'])->toBe($before['counts']['habilitations'] - 1);
});

it('clôture l’activité par la route existante sans retirer d’habilitation permanente ni restituer de badge', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $event = actEvent('stage');
    $person = actPerson('Zzclotureactivite');
    app(Participations::class)->enrol($this->admin, $person, ['event_id' => $event['id'], 'category_id' => $event['category']]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], $this->admin, false, $person);
    $zone = (string) DB::table('zones')->where('event_id', $event['id'])->value('id');
    $permanent = app(HolderEntitlements::class)->grant($holder, $event['id'], $zone, now()->subDay()->toIso8601String(), now()->addDays(20)->toIso8601String(), $this->admin);
    $this->actingAs($this->admin)->post("/administration/evenements/{$event['id']}/cloturer", ['confirmation' => true]);
    expect(DB::table('holder_entitlements')->where('id', $permanent)->value('revoked_at'))->toBeNull()
        ->and(DB::table('permanent_holders')->where('id', $holder)->value('status'))->toBe('actif');
});

// --- Fonctions conservées : anciens écrans toujours servis ---------------------------------------------------------------------------------

it('conserve les parcours existants rattachés aux activités : fiches d’invités, imports, personnes, fiche complète', function () {
    $person = actPerson('Zzconserve');
    $this->actingAs($this->admin);
    foreach (['/administration/invites', '/administration/imports', '/administration/personnes', "/administration/personnes/{$person}", '/administration/categories', '/administration'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('ne crée ni ne possède de poste : une activité clôturée laisse les postes du site intacts', function () {
    $posts = DB::table('checkpoints')->count();
    $event = actEvent('visite');
    $this->actingAs($this->admin)->post("/administration/evenements/{$event['id']}/cloturer", ['confirmation' => true]);
    expect(DB::table('checkpoints')->count())->toBe($posts);
});
