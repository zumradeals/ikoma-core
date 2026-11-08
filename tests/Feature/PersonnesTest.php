<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use App\Services\Poste\ListSnapshot;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * S03/S04 — personnes (identité unique), activités indépendantes, participations persistantes. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function pplUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif personnes', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{id: string, category: string} une activité ouverte avec une catégorie */
function pplEvent(string $kind = 'stage', ?string $status = null): array
{
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => $kind, 'status' => $status, ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);

    return ['id' => $id, 'category' => $category];
}

function pplVisitor(array $event, string $name, ?string $personId = null): string
{
    $id = (string) Str::uuid();
    DB::table('visitors')->insert(['id' => $id, 'event_id' => $event['id'], 'person_id' => $personId, 'full_name' => $name, 'category_id' => $event['category'], 'valid_from' => '2027-01-01 08:00:00+00', 'valid_until' => '2027-03-01 18:00:00+00',
        'source' => 'listé', 'status' => 'attendu', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = pplUser('administrateur');
    $this->agent = pplUser('agent_accueil');
    $this->actingAs($this->admin);
});

// --- Identité unique ------------------------------------------------------------------------------------------------------------------

it('rattache toute participation à une personne et ne fusionne jamais deux participations de même nom', function () {
    $before = DB::table('persons')->count();
    $stage = pplEvent('stage');
    $visit = pplEvent('visite');
    $first = pplVisitor($stage, 'Koné Mariamzz');
    $second = pplVisitor($visit, 'Koné Mariamzz');
    $p1 = DB::table('visitors')->where('id', $first)->value('person_id');
    $p2 = DB::table('visitors')->where('id', $second)->value('person_id');
    expect($p1)->not->toBeNull()->and($p2)->not->toBeNull()->and($p1)->not->toBe($p2);
    expect(DB::table('persons')->count())->toBe($before + 2)
        ->and(DB::table('persons')->where('id', $p1)->value('full_name'))->toBe('Koné Mariamzz');
});

it('garantit en base une seule participation par personne et par activité, et refuse une participation sans personne existante', function () {
    $stage = pplEvent('stage');
    $first = pplVisitor($stage, 'Yao Brou');
    $person = DB::table('visitors')->where('id', $first)->value('person_id');
    expect(fn () => DB::transaction(fn () => pplVisitor($stage, 'Yao Brou', $person)))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => pplVisitor($stage, 'Fantôme', (string) Str::uuid())))->toThrow(QueryException::class);
    // la même personne participe librement à une AUTRE activité
    $visit = pplEvent('visite');
    $second = pplVisitor($visit, 'Yao Brou', $person);
    expect(DB::table('visitors')->where('id', $second)->value('person_id'))->toBe($person);
});

it('exige une confirmation explicite pour créer un homonyme et ne journalise aucun nom', function () {
    $persons = app(Persons::class);
    $first = $persons->create($this->admin, ['first_name' => 'Awa', 'last_name' => 'Traoréxx', 'organisation' => 'Atelier fictif']);
    expect(DB::table('persons')->where('id', $first)->value('full_name'))->toBe('Awa Traoré');
    expect(fn () => $persons->create($this->admin, ['full_name' => 'awa traoréxx']))->toThrow(ValidationException::class, 'de même nom');
    expect(DB::table('persons')->whereRaw("public.ikoma_normalize(full_name) = 'awa traorexx'")->count())->toBe(1);
    $second = $persons->create($this->admin, ['full_name' => 'Awa Traoréxx'], true);
    expect($second)->not->toBe($first)->and(DB::table('persons')->whereRaw("public.ikoma_normalize(full_name) = 'awa traorexx'")->count())->toBe(2);
    // prénom sans nom : refusé ; seuls des identifiants figurent dans le journal
    expect(fn () => $persons->create($this->admin, ['first_name' => 'Awa', 'last_name' => '']))->toThrow(ValidationException::class);
    $details = DB::table('audit_logs')->where('action', 'personne.creee')->pluck('details')->implode(' ');
    expect($details)->toContain($first)->and($details)->not->toContain('Traoréxx');
    // seul un compte autorisé peut créer
    expect(fn () => $persons->create($this->agent, ['full_name' => 'Interdit fictif']))->toThrow(HttpException::class);
});

it('retrouve une personne sans tenir compte des accents ni de l’ordre des mots, avec son nombre de participations', function () {
    $stage = pplEvent('stage');
    $visit = pplEvent('visite');
    $v = pplVisitor($stage, 'Koné Mariamzz Fictive');
    $person = DB::table('visitors')->where('id', $v)->value('person_id');
    pplVisitor($visit, 'Koné Mariamzz Fictive', $person);
    $found = app(Persons::class)->search('mariamzz KONE');
    $row = collect($found)->firstWhere('id', $person);
    expect($row)->not->toBeNull()->and((int) $row->participations)->toBe(2);
    expect(collect(app(Persons::class)->search('inconnu-absolu'))->pluck('id')->all())->not->toContain($person);
});

// --- Activités et participations indépendantes ---------------------------------------------------------------------------------------

it('inscrit une personne à plusieurs activités indépendantes et refuse les inscriptions incohérentes', function () {
    $participations = app(Participations::class);
    $person = app(Persons::class)->create($this->admin, ['first_name' => 'Ismaël', 'last_name' => 'Diarra']);
    $stage1 = pplEvent('stage');
    $stage2 = pplEvent('stage');
    $a = $participations->enrol($this->admin, $person, ['event_id' => $stage1['id'], 'category_id' => $stage1['category']]);
    $b = $participations->enrol($this->admin, $person, ['event_id' => $stage2['id'], 'category_id' => $stage2['category']]);
    expect(DB::table('visitors')->whereIn('id', [$a, $b])->pluck('person_id')->unique()->all())->toBe([$person]);
    expect(DB::table('visitors')->where('id', $a)->value('full_name'))->toBe('Ismaël Diarra')->and(DB::table('visitors')->where('id', $b)->value('event_id'))->toBe($stage2['id']);
    // déjà inscrite, catégorie d'une autre activité, activité clôturée, archivée, permanente, personne archivée
    expect(fn () => $participations->enrol($this->admin, $person, ['event_id' => $stage1['id'], 'category_id' => $stage1['category']]))->toThrow(ValidationException::class, 'déjà');
    $other = pplEvent('visite');
    expect(fn () => $participations->enrol($this->admin, $person, ['event_id' => $other['id'], 'category_id' => $stage1['category']]))->toThrow(ValidationException::class, 'Catégorie');
    foreach (['clos', 'archive'] as $status) {
        $closed = pplEvent('stage', $status);
        expect(fn () => $participations->enrol($this->admin, $person, ['event_id' => $closed['id'], 'category_id' => $closed['category']]))->toThrow(ValidationException::class, 'clôturée');
    }
    DB::table('persons')->where('id', $person)->update(['status' => 'archive']);
    expect(fn () => $participations->enrol($this->admin, $person, ['event_id' => $other['id'], 'category_id' => $other['category']]))->toThrow(ValidationException::class, 'archivée');
    // seul un compte autorisé inscrit
    expect(fn () => $participations->enrol($this->agent, $person, ['event_id' => $other['id'], 'category_id' => $other['category']]))->toThrow(HttpException::class);
});

it('clôture une participation sans toucher à la personne ni à ses autres participations, et sans jamais valoir restitution', function () {
    $participations = app(Participations::class);
    $stage1 = pplEvent('stage');
    $stage2 = pplEvent('stage');
    $person = app(Persons::class)->create($this->admin, ['full_name' => 'Fatou Sanogo']);
    $one = $participations->enrol($this->admin, $person, ['event_id' => $stage1['id'], 'category_id' => $stage1['category']]);
    $two = $participations->enrol($this->admin, $person, ['event_id' => $stage2['id'], 'category_id' => $stage2['category']]);
    // motif obligatoire
    expect(fn () => $participations->close($this->admin, $one, '  '))->toThrow(ValidationException::class, 'motif');
    // présente : refus ; badge attribué : refus (la clôture ne restitue jamais)
    DB::table('visitors')->where('id', $one)->update(['status' => 'present']);
    expect(fn () => $participations->close($this->admin, $one, 'Fin du stage'))->toThrow(ValidationException::class, 'présente');
    DB::table('visitors')->where('id', $one)->update(['status' => 'sorti']);
    $batch = app(BadgeLots::class)->generate($stage1['id'], $stage1['category'], 3, $this->admin);
    $badge = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->first();
    app(BadgeService::class)->associer($badge, $one, $this->agent);
    expect(fn () => $participations->close($this->admin, $one, 'Fin du stage'))->toThrow(ValidationException::class, 'restituez');
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // restitution explicite, puis clôture
    app(BadgeService::class)->restituer($badge, $this->agent);
    $participations->close($this->admin, $one, 'Fin du stage');
    expect(DB::table('visitors')->where('id', $one)->value('closed_at'))->not->toBeNull()->and(DB::table('visitors')->where('id', $one)->value('closure_reason'))->toBe('Fin du stage');
    // la personne et son autre participation sont intactes ; l'historique des attributions aussi
    expect(DB::table('persons')->where('id', $person)->value('full_name'))->toBe('Fatou Sanogo')->and(DB::table('persons')->where('id', $person)->value('status'))->toBe('actif');
    expect(DB::table('visitors')->where('id', $two)->value('closed_at'))->toBeNull()->and(DB::table('visitors')->where('id', $two)->value('status'))->toBe('attendu');
    expect(DB::table('badge_assignments')->where('visitor_id', $one)->whereNotNull('returned_at')->count())->toBe(1);
    // double clôture refusée ; participation de titulaire permanent exclue
    expect(fn () => $participations->close($this->admin, $one, 'Encore'))->toThrow(ValidationException::class, 'déjà clôturée');
});

it('exclut une participation clôturée de la recherche, de la copie de la tablette et de toute attribution ou contrôle', function () {
    $participations = app(Participations::class);
    $event = DemoSeeder::id('event');
    $visitor = DemoSeeder::id('visitor5');
    $batch = app(BadgeLots::class)->generate($event, DemoSeeder::id('category2'), 3, $this->admin);
    $badges = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get();
    // le visiteur 5 relève de la catégorie 2 (jeu fictif : i % 3) ; badge attribué puis restitué avant clôture
    app(BadgeService::class)->associer($badges[0], $visitor, $this->agent);
    expect(DB::table('visitors')->where('id', $visitor)->value('category_id'))->toBe(DemoSeeder::id('category2'));
    app(BadgeService::class)->restituer($badges[0], $this->agent);
    $participations->close($this->admin, $visitor, 'Participation terminée');
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    expect(collect(app(ListSnapshot::class)->build($poste)['visitors'])->pluck('id')->all())->not->toContain($visitor);
    expect(fn () => app(BadgeService::class)->associer($badges[1], $visitor, $this->agent))->toThrow(BadgeRefused::class, 'clôturée');
});

// --- Écrans et routes ----------------------------------------------------------------------------------------------------------------

it('réserve le registre des personnes à l’administration', function () {
    $person = DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('person_id');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(pplUser($role));
        $this->get('/administration/personnes')->assertForbidden();
        $this->getJson('/administration/personnes/recherche?q=fictif')->assertForbidden();
        $this->get('/administration/personnes/'.$person)->assertForbidden();
        $this->post('/administration/personnes', ['full_name' => 'Interdit fictif'])->assertForbidden();
        $this->post('/administration/personnes/'.$person.'/participations', ['event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category1')])->assertForbidden();
        $this->post('/administration/participations/'.DemoSeeder::id('visitor1').'/cloturer', ['reason' => 'x'])->assertForbidden();
    }
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('closed_at'))->toBeNull();
});

it('affiche le registre, la fiche avec son historique, et permet d’inscrire puis de clôturer par les routes', function () {
    $person = app(Persons::class)->create($this->admin, ['first_name' => 'Salif', 'last_name' => 'Zzyxwv']);
    $this->actingAs($this->admin);
    $this->get('/administration/personnes?q=zzyxwv')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Personnes')->where('persons.0.id', $person));
    $this->getJson('/administration/personnes/recherche?q=zzyxwv')->assertOk()->assertJsonPath('results.0.id', $person);
    $this->getJson('/administration/personnes/recherche?q=s')->assertUnprocessable();
    $stage = pplEvent('stage');
    $this->post('/administration/personnes/'.$person.'/participations', ['event_id' => $stage['id'], 'category_id' => $stage['category']])->assertRedirect('/administration/personnes/'.$person);
    $participation = DB::table('visitors')->where('person_id', $person)->where('event_id', $stage['id'])->value('id');
    expect($participation)->not->toBeNull();
    $this->get('/administration/personnes/'.$person)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Personne')->where('person.id', $person)->has('participations', 1)->where('participations.0.event_kind', 'stage')->has('assignments', 0));
    $this->post('/administration/participations/'.$participation.'/cloturer', ['reason' => 'Fin du stage'])->assertRedirect('/administration/personnes/'.$person);
    expect(DB::table('visitors')->where('id', $participation)->value('closed_at'))->not->toBeNull();
    $this->post('/administration/participations/'.$participation.'/cloturer', ['reason' => 'Encore'])->assertSessionHasErrors('participation');
    // création explicite : un homonyme demande une confirmation, la deuxième personne est alors distincte
    $this->post('/administration/personnes', ['first_name' => 'Salif', 'last_name' => 'Zzyxwv'])->assertSessionHasErrors('full_name');
    $this->post('/administration/personnes', ['first_name' => 'Salif', 'last_name' => 'Zzyxwv', 'homonym_confirmed' => true])->assertRedirect();
    expect(DB::table('persons')->whereRaw("public.ikoma_normalize(full_name) = 'salif zzyxwv'")->count())->toBe(2);
});

it('inscrit une personne connue depuis la saisie d’un invité, sans en créer une nouvelle ni la dupliquer dans l’activité', function () {
    $person = app(Persons::class)->create($this->admin, ['first_name' => 'Aïcha', 'last_name' => 'Bamba', 'organisation' => 'Partenaire fictif']);
    $before = DB::table('persons')->count();
    $input = ['event_id' => DemoSeeder::id('event'), 'person_id' => $person, 'full_name' => 'Nom saisi ignoré', 'organisation' => 'Partenaire fictif', 'position' => 'Stagiaire', 'host_id' => DemoSeeder::id('host0'),
        'category_id' => DemoSeeder::id('category1'), 'group_id' => null, 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 20:00', 'remove_photo' => false, 'photo_print_allowed' => false];
    $this->post('/administration/invites', $input)->assertRedirect();
    $row = DB::table('visitors')->where('person_id', $person)->where('event_id', DemoSeeder::id('event'))->first();
    expect($row)->not->toBeNull()->and($row->full_name)->toBe('Aïcha Bamba'); // l'identité de référence prime sur la saisie
    expect(DB::table('persons')->count())->toBe($before);
    // la même personne ne participe pas deux fois à la même activité
    $this->post('/administration/invites', $input)->assertSessionHasErrors('full_name');
    expect(DB::table('visitors')->where('person_id', $person)->where('event_id', DemoSeeder::id('event'))->count())->toBe(1);
    // une personne inconnue est refusée
    $this->post('/administration/invites', [...$input, 'person_id' => (string) Str::uuid()])->assertSessionHasErrors('person_id');
});

// --- Nature de l'activité ---------------------------------------------------------------------------------------------------------------

it('porte la nature de l’activité (événement, stage, visite) et réserve « permanent » au contexte permanent', function () {
    $payload = ['name' => 'Stage fictif de sécurité', 'site' => 'Site fictif', 'starts_at' => '2030-11-10T08:00', 'ends_at' => '2030-11-14T18:00'];
    $this->post('/administration/evenements', [...$payload, 'kind' => 'stage'])->assertRedirect();
    expect(DB::table('events')->where('name', 'Stage fictif de sécurité')->value('kind'))->toBe('stage');
    $this->post('/administration/evenements', [...$payload, 'name' => 'Sans nature fictif'])->assertRedirect();
    expect(DB::table('events')->where('name', 'Sans nature fictif')->value('kind'))->toBe('evenement');
    $this->post('/administration/evenements', [...$payload, 'name' => 'Nature inconnue', 'kind' => 'permanent'])->assertSessionHasErrors('kind');
    expect(DB::table('events')->where('name', 'Nature inconnue')->exists())->toBeFalse();
    $event = DB::table('events')->where('name', 'Stage fictif de sécurité')->value('id');
    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $event)->update(['kind' => 'permanent'])))->toThrow(QueryException::class, 'réservée');
    expect(fn () => DB::transaction(fn () => DB::table('events')->where('id', $event)->update(['kind' => 'inconnue'])))->toThrow(QueryException::class);
});

// --- Personnel permanent : une personne, avec son profil d'engagement ----------------------------------------------------------------

it('fait d’un titulaire permanent une personne : même identité, historique commun, aucune duplication', function () {
    $holder = app(PermanentHolders::class)->create(['first_name' => 'Awa', 'last_name' => 'Fictive'.Str::random(4), 'position' => 'Comptable'], $this->admin);
    $row = DB::table('permanent_holders')->where('id', $holder)->first();
    expect($row->person_id)->not->toBeNull();
    $person = DB::table('persons')->where('id', $row->person_id)->first();
    expect($person->full_name)->toBe($row->full_name)->and($person->first_name)->toBe('Awa');
    // la même personne peut en outre participer à une activité ordinaire : une seule identité
    $stage = pplEvent('stage');
    $participation = app(Participations::class)->enrol($this->admin, $row->person_id, ['event_id' => $stage['id'], 'category_id' => $stage['category']]);
    $history = app(Persons::class)->history($row->person_id);
    expect($history['holder']->identifier)->toBe($row->identifier)->and(collect($history['participations'])->pluck('id')->all())->toContain($participation);
    expect(DB::table('persons')->where('full_name', $row->full_name)->count())->toBe(1);
    // le profil permanent ne se détache jamais de sa personne, ni une participation de la sienne
    $other = DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('person_id');
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $holder)->update(['person_id' => $other])))->toThrow(QueryException::class, 'ne change jamais de personne');
    expect(fn () => DB::transaction(fn () => DB::table('visitors')->where('id', $participation)->update(['person_id' => $other])))->toThrow(QueryException::class, 'ne change jamais de personne');
});
