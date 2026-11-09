<?php

use App\Models\User;
use App\Services\HolderEntitlements;
use App\Services\Imports\HolderImport;
use App\Services\InstitutionalCategories;
use App\Services\ParticipationEntitlements;
use App\Services\PermanentHolders;
use App\Services\PersonRegistry;
use App\Services\Persons;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * V1 / lot P2 — réutilisation des identités et cohérence des titulaires permanents. Données fictives uniquement.
 * `persons` est la référence canonique de l'identité ; un profil permanent se rattache à une personne existante ou se crée avec une nouvelle personne,
 * sans rapprochement automatique sur le nom ; un nom se corrige dans la personne, sans réécrire l'historique. Garanties portées par la base et le service.
 */

uses(DatabaseTransactions::class);

function idtUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif identités', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** Personne déjà inscrite à une activité (participation de la démonstration), sans profil permanent. */
function idtParticipant(): string
{
    return (string) DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('person_id');
}

function idtName(): string
{
    return 'Zzidentite '.Str::lower(Str::random(8));
}

/** @return array<string, int> */
function idtCounts(): array
{
    $counts = [];
    foreach (['persons', 'permanent_holders', 'visitors', 'badge_assignments', 'participation_entitlements', 'holder_entitlements'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function idtProfile(array $over = []): array
{
    return ['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01', ...$over];
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = idtUser('administrateur');
    $this->agent = idtUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->holders = app(PermanentHolders::class);
});

// --- Cas A : une personne existante devient titulaire permanent ----------------------------------------------------------------

it('rattache un profil permanent à une personne déjà inscrite à une activité : même identité, aucune création, rien d’autre ne change', function () {
    $person = idtParticipant();
    $row = DB::table('persons')->where('id', $person)->first();
    $participation = DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->first();
    $before = idtCounts();

    // Une identité différente saisie dans le formulaire n'est jamais prise en compte : l'identité est celle de `persons`.
    $holder = $this->holders->create(idtProfile(['full_name' => 'Nom Saisi Ailleurs', 'organisation' => 'Autre organisation']), $this->admin, false, $person);

    $created = DB::table('permanent_holders')->where('id', $holder)->first();
    expect($created->person_id)->toBe($person)->and($created->full_name)->toBe($row->full_name)->and($created->organisation)->toBe($row->organisation)
        ->and(idtCounts())->toBe([...$before, 'permanent_holders' => $before['permanent_holders'] + 1]);
    $after = DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->first();
    expect($after->full_name)->toBe($participation->full_name)->and($after->status)->toBe($participation->status)->and($after->person_id)->toBe($person);
    expect((string) DB::table('audit_logs')->where('action', 'titulaire.cree')->orderByDesc('id')->value('details'))->toContain($person);
});

it('refuse de rattacher un profil à une personne inexistante, archivée ou déjà titulaire, sans rien créer', function () {
    $person = idtParticipant();
    $this->holders->create(idtProfile(), $this->admin, false, $person);
    $before = idtCounts();

    expect(fn () => $this->holders->create(idtProfile(), $this->admin, false, $person))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->create(idtProfile(), $this->admin, false, (string) Str::uuid()))->toThrow(ValidationException::class);
    $archived = app(Persons::class)->create($this->admin, ['full_name' => idtName()]);
    DB::table('persons')->where('id', $archived)->update(['status' => 'archive']);
    expect(fn () => $this->holders->create(idtProfile(), $this->admin, false, $archived))->toThrow(ValidationException::class);

    expect(DB::table('permanent_holders')->where('person_id', $person)->count())->toBe(1)->and(idtCounts()['permanent_holders'])->toBe($before['permanent_holders'])
        ->and(idtCounts()['persons'])->toBe($before['persons'] + 1);
});

it('ne laisse aucun rattachement partiel quand l’enregistrement échoue', function () {
    $person = idtParticipant();
    $before = idtCounts();

    expect(fn () => $this->holders->create(idtProfile(['institutional_category' => 'AILCT-42']), $this->admin, false, $person))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->create(['full_name' => idtName(), 'institutional_category' => 'AILCT-42'], $this->admin))->toThrow(ValidationException::class);
    expect(idtCounts())->toBe($before);

    // Une erreur APRÈS l'enregistrement annule aussi bien la personne que le profil : une seule transaction.
    $name = idtName();
    try {
        DB::transaction(function () use ($name): void {
            $this->holders->create(['full_name' => $name], $this->admin);
            throw new RuntimeException('Échec simulé après l’enregistrement');
        });
    } catch (RuntimeException) {
    }
    expect(idtCounts())->toBe($before)->and(DB::table('persons')->where('full_name', $name)->exists())->toBeFalse();
});

// --- Cas B : nouvelle personne ---------------------------------------------------------------------------------------------

it('crée une seule identité pour une nouvelle personne permanente, avec son profil, dans la même opération', function () {
    $name = idtName();
    $before = idtCounts();

    $holder = $this->holders->create(['full_name' => $name, ...idtProfile()], $this->admin);

    $row = DB::table('permanent_holders')->where('id', $holder)->first();
    expect(idtCounts())->toBe([...$before, 'persons' => $before['persons'] + 1, 'permanent_holders' => $before['permanent_holders'] + 1])
        ->and(DB::table('persons')->where('full_name', $name)->count())->toBe(1)
        ->and(DB::table('persons')->where('id', $row->person_id)->value('full_name'))->toBe($name);
});

it('garde distinctes deux personnes homonymes : la création sur un nom connu exige une confirmation explicite et ne fusionne jamais', function () {
    $name = idtName();
    $first = app(Persons::class)->create($this->admin, ['full_name' => $name]);
    $before = idtCounts();

    expect(fn () => $this->holders->create(['full_name' => $name, ...idtProfile()], $this->admin))->toThrow(ValidationException::class);
    expect(idtCounts())->toBe($before);

    $holder = $this->holders->create(['full_name' => $name, ...idtProfile()], $this->admin, true);
    $second = (string) DB::table('permanent_holders')->where('id', $holder)->value('person_id');
    expect($second)->not->toBe($first)->and(DB::table('persons')->where('full_name', $name)->count())->toBe(2)
        ->and(DB::table('permanent_holders')->where('person_id', $first)->exists())->toBeFalse();
});

// --- Contraintes d'intégrité (garanties de la base) --------------------------------------------------------------------------

it('garantit en base un seul profil permanent par personne, un rattachement à une personne existante et l’identité canonique', function () {
    $person = idtParticipant();
    $this->holders->create(idtProfile(), $this->admin, false, $person);
    $row = DB::table('persons')->where('id', $person)->first();
    $base = ['identifier' => 'ZZ-'.Str::random(8), 'identifier_seq' => random_int(900000, 999999), 'full_name' => $row->full_name, 'first_name' => $row->first_name, 'last_name' => $row->last_name,
        'organisation' => $row->organisation, 'status' => 'actif', 'created_at' => now(), 'updated_at' => now()];

    // Deux requêtes concurrentes pour la même personne : la seconde ne peut pas aboutir (index unique), jamais deux profils.
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->insert(['id' => (string) Str::uuid(), 'person_id' => $person, ...$base])))->toThrow(QueryException::class);
    // Rattachement à une personne inexistante : refusé.
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->insert(['id' => (string) Str::uuid(), 'person_id' => (string) Str::uuid(), ...$base, 'identifier' => 'ZZ-'.Str::random(8), 'identifier_seq' => random_int(800000, 899999)])))->toThrow(QueryException::class);
    // Un nom écrit directement sur un profil, qui différerait de la personne, est refusé : une seule source de vérité.
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('person_id', $person)->update(['full_name' => 'Contournement', 'first_name' => null, 'last_name' => null])))->toThrow(QueryException::class);
    expect(DB::table('permanent_holders')->where('person_id', $person)->count())->toBe(1)->and(DB::table('permanent_holders')->where('person_id', $person)->value('full_name'))->toBe($row->full_name);
});

// --- Cohérence des noms ----------------------------------------------------------------------------------------------------

it('corrige un nom dans la personne, le propage au profil actif et ne réécrit ni les participations, ni le journal', function () {
    $person = idtParticipant();
    $holder = $this->holders->create(idtProfile(), $this->admin, false, $person);
    $participation = DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->first();
    $creation = DB::table('audit_logs')->where('action', 'titulaire.cree')->orderByDesc('id')->first();
    $new = idtName();

    $changed = app(Persons::class)->correct($this->admin, $person, ['full_name' => $new]);

    expect($changed)->toContain('full_name')->and(DB::table('persons')->where('id', $person)->value('full_name'))->toBe($new)
        ->and(DB::table('permanent_holders')->where('id', $holder)->value('full_name'))->toBe($new);
    // Historique : la participation garde le nom du moment de l'inscription, la ligne de journal de la création est inchangée.
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('full_name'))->toBe($participation->full_name);
    $after = DB::table('audit_logs')->where('id', $creation->id)->first();
    expect($after->details)->toBe($creation->details)->and($after->action)->toBe($creation->action);
    // La correction est tracée : champs seulement, jamais les valeurs.
    $entry = DB::table('audit_logs')->where('action', 'identite.corrigee')->orderByDesc('id')->first();
    expect($entry)->not->toBeNull()->and((string) $entry->details)->toContain($person)->toContain('full_name')->and((string) $entry->details)->not->toContain($new);
    // Visible dans le registre unifié et la fiche.
    $registry = app(PersonRegistry::class);
    expect(array_map(fn ($r) => $r->id, $registry->list(['q' => $new])->items()))->toContain($person)->and($registry->profile($person)['person']->full_name)->toBe($new);
});

it('corrige prénom et nom ensemble, et refuse une correction qui créerait un doublon ou un homonyme non confirmé', function () {
    $name = idtName();
    $other = app(Persons::class)->create($this->admin, ['full_name' => $name]);
    $person = idtParticipant();

    expect(fn () => app(Persons::class)->correct($this->admin, $person, ['full_name' => $name]))->toThrow(ValidationException::class);
    expect(app(Persons::class)->correct($this->admin, $person, ['full_name' => $name], true))->toContain('full_name');
    expect(DB::table('persons')->where('full_name', $name)->count())->toBe(2)->and($other)->not->toBe($person);

    expect(app(Persons::class)->correct($this->admin, $person, ['first_name' => 'Awa', 'last_name' => 'Corrigée'.Str::lower(Str::random(4))]))->toContain('first_name')
        ->and(app(Persons::class)->correct($this->admin, $person, ['first_name' => 'Awa', 'last_name' => (string) DB::table('persons')->where('id', $person)->value('last_name')]))->toBe([]);
});

it('route la modification du nom d’un titulaire vers la personne, et ne réécrit pas un profil clos', function () {
    $name = idtName();
    $holder = $this->holders->create(['full_name' => $name, ...idtProfile()], $this->admin);
    $person = (string) DB::table('permanent_holders')->where('id', $holder)->value('person_id');
    $renamed = idtName();

    $changed = $this->holders->update($holder, ['full_name' => $renamed, ...idtProfile()], $this->admin);

    expect($changed)->toContain('full_name')->and(DB::table('persons')->where('id', $person)->value('full_name'))->toBe($renamed)
        ->and(DB::table('permanent_holders')->where('id', $holder)->value('full_name'))->toBe($renamed)
        ->and(DB::table('audit_logs')->where('action', 'identite.corrigee')->exists())->toBeTrue();

    // Titulaire clos : il garde le nom du moment de sa clôture ; l'identité canonique, elle, peut encore être corrigée.
    $this->holders->close($holder, 'Fin d’engagement fictive', $this->admin);
    $latest = idtName();
    app(Persons::class)->correct($this->admin, $person, ['full_name' => $latest]);
    expect(DB::table('persons')->where('id', $person)->value('full_name'))->toBe($latest)->and(DB::table('permanent_holders')->where('id', $holder)->value('full_name'))->toBe($renamed);
});

// --- Imports --------------------------------------------------------------------------------------------------------------

it('ne rapproche jamais automatiquement une ligne d’import sur le nom d’une personne existante : signalée, créée seulement si elle est confirmée', function () {
    $name = idtName();
    $known = app(Persons::class)->create($this->admin, ['full_name' => $name]);
    $import = app(HolderImport::class);
    $before = idtCounts();

    $import->preview($this->admin, UploadedFile::fake()->createWithContent('personnes.csv', "nom complet\n{$name}\n"));
    $preview = $import->show($this->admin);
    expect(array_column($preview['rows'], 'status', 'line'))->toBe([2 => 'homonyme'])->and($preview['summary'])->toMatchArray(['a_creer' => 0, 'homonymes' => 1]);

    $result = $import->confirm($this->admin, $preview['id'], []);
    expect($result)->toMatchArray(['created' => 0, 'ignored' => 1])->and(idtCounts())->toBe($before);

    $import->preview($this->admin, UploadedFile::fake()->createWithContent('personnes.csv', "nom complet\n{$name}\n"));
    expect($import->confirm($this->admin, $import->show($this->admin)['id'], [2])['created'])->toBe(1);
    // Aucune fusion : la personne connue reste sans profil, une autre identité distincte est créée.
    expect(DB::table('permanent_holders')->where('person_id', $known)->exists())->toBeFalse()->and(DB::table('persons')->where('full_name', $name)->count())->toBe(2);
});

// --- Habilitations ----------------------------------------------------------------------------------------------------------

it('laisse indépendants les deux moteurs d’habilitation : ajouter un profil ne touche pas aux droits de participation', function () {
    $person = idtParticipant();
    app(ParticipationEntitlements::class)->grant(DemoSeeder::id('visitor1'), DemoSeeder::id('zone1'), 'standard', null, now()->subDay()->toIso8601String(), now()->addDays(10)->toIso8601String(), $this->admin);
    $before = idtCounts();

    $holder = $this->holders->create(idtProfile(), $this->admin, false, $person);

    expect(idtCounts()['participation_entitlements'])->toBe($before['participation_entitlements'])->and(idtCounts()['holder_entitlements'])->toBe($before['holder_entitlements']);
    app(HolderEntitlements::class)->grant($holder, DemoSeeder::id('event'), DemoSeeder::id('zone0'), now()->subDay()->toIso8601String(), now()->addDays(10)->toIso8601String(), $this->admin);
    $profile = app(PersonRegistry::class)->profile($person);
    expect(idtCounts()['participation_entitlements'])->toBe($before['participation_entitlements'])->and($profile['entitlements']['permanentes'])->toHaveCount(1)
        ->and($profile['entitlements']['activites'])->toHaveCount(1)->and($profile['entitlements']['activites'][0]['origin'])->toBe('activite');
});

// --- Permissions serveur ---------------------------------------------------------------------------------------------------

it('réserve la création d’un profil permanent et la correction d’identité à la permission « Personnes et listes », côté serveur', function () {
    $person = idtParticipant();
    $before = idtCounts();
    $name = (string) DB::table('persons')->where('id', $person)->value('full_name');

    $this->actingAs($this->agent);
    $this->post('/administration/titulaires', ['person_id' => $person, 'position' => 'Tentative'])->assertForbidden();
    $this->put('/administration/personnes/'.$person, ['full_name' => 'Nom non autorisé'])->assertForbidden();
    expect(fn () => app(Persons::class)->correct($this->agent, $person, ['full_name' => 'Nom non autorisé']))->toThrow(HttpException::class);
    expect(idtCounts())->toBe($before)->and(DB::table('persons')->where('id', $person)->value('full_name'))->toBe($name);

    $this->actingAs($this->admin);
    $this->post('/administration/titulaires', ['person_id' => 'pas-un-identifiant', 'position' => 'X'])->assertSessionHasErrors('person_id');
    $this->post('/administration/titulaires', ['person_id' => $person, 'position' => 'Formateur fictif'])->assertSessionHasNoErrors();
    expect(DB::table('permanent_holders')->where('person_id', $person)->count())->toBe(1);
    $this->post('/administration/titulaires', ['person_id' => $person, 'position' => 'Second'])->assertSessionHasErrors('person_id');
    $this->put('/administration/personnes/'.$person, ['full_name' => 'Nom Corrigé Autorisé'])->assertSessionHasNoErrors();
    expect(DB::table('persons')->where('id', $person)->value('full_name'))->toBe('Nom Corrigé Autorisé')->and(DB::table('permanent_holders')->where('person_id', $person)->count())->toBe(1);
});
