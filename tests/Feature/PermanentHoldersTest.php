<?php

use App\Models\User;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function holderUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif registre', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}

function holderData(array $over = []): array
{
    return array_merge(['full_name' => 'Personne Fictive '.Str::random(8), 'organisation' => 'Organisation fictive', 'position' => 'Fonction fictive', 'institutional_category' => '', 'engagement_starts_on' => '2026-01-05', 'engagement_ends_on' => ''], $over);
}

function holderEvent(): array
{
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif registre', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $cats = [];
    foreach (['Catégorie A', 'Catégorie B'] as $name) {
        $id = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $id, 'event_id' => $event, 'name' => $name, 'color' => '#336699', 'priority_circuit' => false, 'created_at' => now(), 'updated_at' => now()]);
        $cats[] = $id;
    }

    return [$event, $cats];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = holderUser('administrateur');
    $this->holders = app(PermanentHolders::class);
});

// --- A04.1 registre ------------------------------------------------------------------------------------------------

it('crée un titulaire avec un identifiant imprimable et journalise sans donnée personnelle', function () {
    $this->actingAs($this->admin)->post('/administration/titulaires', holderData(['full_name' => 'Aminata Fictive Koné']))->assertRedirect();
    $holder = DB::table('permanent_holders')->first();
    expect($holder->status)->toBe('actif')->and($holder->identifier)->toMatch('/^ID-\d{6}-\d$/')->and($holder->identifier_seq)->toBeGreaterThan(0)->and($holder->created_by)->toBe($this->admin->id);
    $details = DB::table('audit_logs')->where('action', 'titulaire.cree')->value('details');
    expect($details)->toContain($holder->identifier)->and($details)->not->toContain('Aminata')->and($details)->not->toContain('Organisation');
});

it('consulte et recherche les titulaires sans tenir compte des accents ni de la casse', function () {
    $a = $this->holders->create(holderData(['full_name' => 'Élodie Fictive Zébré', 'organisation' => 'Atelier fictif']), $this->admin);
    $this->holders->create(holderData(['full_name' => 'Bertrand Fictif Yao', 'organisation' => 'Service fictif']), $this->admin);
    $this->holders->close($this->holders->create(holderData(['full_name' => 'Claire Fictive Clos']), $this->admin), 'Fin d’engagement fictive', $this->admin);
    $this->actingAs($this->admin)->get('/administration/titulaires?q=elodie')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Titulaires')->where('holders.total', 1)->where('holders.data.0.id', $a));
    $this->get('/administration/titulaires?q=SERVICE')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1));
    $identifier = DB::table('permanent_holders')->where('id', $a)->value('identifier');
    $this->get('/administration/titulaires?q='.$identifier)->assertInertia(fn (Assert $p) => $p->where('holders.total', 1));
    $this->get('/administration/titulaires')->assertInertia(fn (Assert $p) => $p->where('holders.total', 2));
    $this->get('/administration/titulaires?statut=clos')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1));
    $this->get('/administration/titulaires?statut=tous')->assertInertia(fn (Assert $p) => $p->where('holders.total', 3));
});

it('modifie un titulaire sans jamais changer son identifiant et journalise les noms de champs seulement', function () {
    $id = $this->holders->create(holderData(['full_name' => 'Fatou Fictive']), $this->admin);
    $before = DB::table('permanent_holders')->where('id', $id)->first();
    $this->actingAs($this->admin)->put('/administration/titulaires/'.$id, holderData(['full_name' => 'Fatou Fictive', 'position' => 'Nouvelle fonction fictive']))->assertRedirect();
    $after = DB::table('permanent_holders')->where('id', $id)->first();
    expect($after->identifier)->toBe($before->identifier)->and($after->identifier_seq)->toBe($before->identifier_seq)->and($after->position)->toBe('Nouvelle fonction fictive')->and($after->updated_by)->toBe($this->admin->id);
    $details = DB::table('audit_logs')->where('action', 'titulaire.modifie')->value('details');
    expect(json_decode($details, true)['champs'])->toBe(['position'])->and($details)->not->toContain('Nouvelle fonction');
    expect($this->holders->update($id, holderData(['full_name' => 'Fatou Fictive', 'position' => 'Nouvelle fonction fictive']), $this->admin))->toBe([]);
});

it('clôture un titulaire avec un motif, de façon irréversible', function () {
    $id = $this->holders->create(holderData(), $this->admin);
    $url = '/administration/titulaires/'.$id.'/cloturer';
    $this->actingAs($this->admin)->post($url, ['reason' => '', 'confirmation' => true])->assertSessionHasErrors('reason');
    $this->post($url, ['reason' => 'Fin de contrat fictive'])->assertSessionHasErrors('confirmation');
    $this->post($url, ['reason' => 'Fin de contrat fictive', 'confirmation' => true])->assertRedirect();
    $holder = DB::table('permanent_holders')->where('id', $id)->first();
    expect($holder->status)->toBe('clos')->and($holder->closed_at)->not->toBeNull()->and($holder->closed_reason)->toBe('Fin de contrat fictive')->and($holder->engagement_ends_on)->not->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'titulaire.clos')->value('details'))->not->toContain('Fin de contrat');
    $this->put('/administration/titulaires/'.$id, holderData())->assertSessionHasErrors('status');
    $this->post($url, ['reason' => 'Encore', 'confirmation' => true])->assertSessionHasErrors('status');
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $id)->update(['position' => 'Contournement', 'updated_at' => now()])))->toThrow(QueryException::class, 'ne se modifie plus');
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $id)->update(['status' => 'actif', 'closed_at' => null])))->toThrow(QueryException::class, 'ne se modifie plus');
});

// --- Doublons --------------------------------------------------------------------------------------------------------

it('empêche les doublons d’un titulaire actif et demande confirmation pour un homonyme', function () {
    $this->holders->create(holderData(['full_name' => 'Moussa Fictif Diallo', 'organisation' => 'Org A']), $this->admin);
    expect(fn () => $this->holders->create(holderData(['full_name' => 'MOUSSA FICTIF DIALLÔ', 'organisation' => 'org a']), $this->admin, true))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->create(holderData(['full_name' => 'Moussa Fictif Diallo', 'organisation' => 'Org B']), $this->admin))->toThrow(ValidationException::class);
    $other = $this->holders->create(holderData(['full_name' => 'Moussa Fictif Diallo', 'organisation' => 'Org B']), $this->admin, true);
    expect(DB::table('permanent_holders')->where('id', $other)->exists())->toBeTrue();
    $this->actingAs($this->admin)->post('/administration/titulaires', holderData(['full_name' => 'Moussa Fictif Diallo', 'organisation' => 'Org A']))->assertSessionHasErrors('full_name');
    expect(DB::table('permanent_holders')->count())->toBe(2);
});

it('autorise un nouveau titulaire de même identité qu’un titulaire clos, avec confirmation et un nouvel identifiant', function () {
    $old = $this->holders->create(holderData(['full_name' => 'Rose Fictive', 'organisation' => 'Org']), $this->admin);
    $this->holders->close($old, 'Départ fictif', $this->admin);
    expect(fn () => $this->holders->create(holderData(['full_name' => 'Rose Fictive', 'organisation' => 'Org']), $this->admin))->toThrow(ValidationException::class);
    $new = $this->holders->create(holderData(['full_name' => 'Rose Fictive', 'organisation' => 'Org']), $this->admin, true);
    expect(DB::table('permanent_holders')->where('id', $new)->value('identifier'))->not->toBe(DB::table('permanent_holders')->where('id', $old)->value('identifier'));
});

it('garde l’index d’unicité comme dernier rempart contre deux créations simultanées', function () {
    $this->holders->create(holderData(['full_name' => 'Course Fictive', 'organisation' => 'Org']), $this->admin);
    $row = ['id' => (string) Str::uuid(), 'identifier' => 'X-000001-0', 'identifier_seq' => 999999, 'full_name' => 'COURSE FICTIVE', 'organisation' => 'org', 'status' => 'actif', 'created_at' => now(), 'updated_at' => now()];
    try {
        DB::transaction(fn () => DB::table('permanent_holders')->insert($row));
        $this->fail('Le doublon aurait dû être refusé par la base.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    }
});

// --- Droits -------------------------------------------------------------------------------------------------------------

it('réserve le registre à l’administrateur et les réglages aux droits de catégories', function () {
    $this->get('/administration/titulaires')->assertRedirect('/login');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(holderUser($role));
        $this->get('/administration/titulaires')->assertForbidden();
        $this->post('/administration/titulaires', holderData())->assertForbidden();
        $this->get('/administration/categories-institutionnelles')->assertForbidden();
        $this->put('/administration/titulaires/format', ['prefix' => 'X', 'width' => 6, 'check' => true])->assertForbidden();
    }
    expect(DB::table('permanent_holders')->count())->toBe(0);
});

// --- A04.4 identifiants -----------------------------------------------------------------------------------------------------

it('émet des identifiants uniques et distincts du jeton QR et du code de badge', function () {
    $ids = array_map(fn () => $this->holders->create(holderData(), $this->admin), range(1, 5));
    $values = DB::table('permanent_holders')->whereIn('id', $ids)->pluck('identifier')->all();
    expect(array_unique($values))->toHaveCount(5);
    foreach ($values as $value) {
        expect($value)->not->toStartWith(QrSvg::PREFIX)->and($value)->not->toMatch('/^B-\d+$/')->and($this->holders->verify($value))->toBeTrue();
    }
    expect($this->holders->verify('ID-000001-9'))->toBeFalse();
});

it('génère des identifiants sans doublon sous accès concurrents', function () {
    $script = '$app = require "'.base_path('bootstrap/app.php').'"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '
        .'for ($i = 0; $i < 25; $i++) { echo Illuminate\Support\Facades\DB::selectOne("SELECT nextval(\'permanent_holder_identifier_seq\') AS n")->n, "\n"; }';
    $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $processes[] = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', 'require "'.base_path('vendor/autoload.php').'"; '.$script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
    }
    $numbers = [];
    foreach ($processes as $i => $process) {
        $numbers = array_merge($numbers, array_filter(explode("\n", stream_get_contents($pipes[$i][1]))));
        proc_close($process);
    }
    expect($numbers)->toHaveCount(150)->and(array_unique($numbers))->toHaveCount(150);
});

it('ne réattribue jamais un identifiant : clôture, annulation de transaction, suppression et modification sont inopérantes', function () {
    $first = $this->holders->create(holderData(), $this->admin);
    $firstRow = DB::table('permanent_holders')->where('id', $first)->first();
    $this->holders->close($first, 'Clôture fictive', $this->admin);
    $second = $this->holders->create(holderData(), $this->admin);
    expect(DB::table('permanent_holders')->where('id', $second)->value('identifier_seq'))->toBeGreaterThan($firstRow->identifier_seq);
    try {
        DB::transaction(function () {
            $this->holders->create(holderData(), $this->admin);
            throw new RuntimeException('annulation fictive');
        });
    } catch (RuntimeException) {
    }
    $third = $this->holders->create(holderData(), $this->admin);
    expect(DB::table('permanent_holders')->where('id', $third)->value('identifier_seq'))->toBeGreaterThan(DB::table('permanent_holders')->where('id', $second)->value('identifier_seq') + 1);
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $first)->delete()))->toThrow(QueryException::class, 'permission denied');
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $second)->update(['identifier' => $firstRow->identifier])))->toThrow(QueryException::class, 'immuable');
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE permanent_holders CASCADE')))->toThrow(QueryException::class, 'permission denied');
});

it('garde l’identifiant émis quand le format change, et le format reste configurable', function () {
    $id = $this->holders->create(holderData(), $this->admin);
    $issued = DB::table('permanent_holders')->where('id', $id)->value('identifier');
    $this->actingAs($this->admin)->put('/administration/titulaires/format', ['prefix' => 'AILCT', 'width' => 8, 'check' => false])->assertRedirect();
    $next = DB::table('permanent_holders')->where('id', $this->holders->create(holderData(), $this->admin))->value('identifier');
    expect($next)->toMatch('/^AILCT-\d{8}$/')->and(DB::table('permanent_holders')->where('id', $id)->value('identifier'))->toBe($issued);
    $this->put('/administration/titulaires/format', ['prefix' => 'mal formé', 'width' => 6, 'check' => true])->assertSessionHasErrors('prefix');
    $this->put('/administration/titulaires/format', ['prefix' => 'AILCT', 'width' => 99, 'check' => true])->assertSessionHasErrors('width');
});

// --- A04.2 contexte permanent -----------------------------------------------------------------------------------------------

it('conserve le registre quand les événements ordinaires sont clos, archivés ou supprimés', function () {
    [$event] = holderEvent();
    $id = $this->holders->create(holderData(), $this->admin);
    DB::table('events')->where('id', $event)->update(['status' => 'clos', 'anonymized_at' => now()]);
    expect(DB::table('permanent_holders')->where('id', $id)->where('status', 'actif')->exists())->toBeTrue();
    DB::table('categories')->where('event_id', $event)->delete();
    DB::table('events')->where('id', $event)->delete();
    expect(DB::table('permanent_holders')->where('id', $id)->exists())->toBeTrue();
});

// --- A04.3 catégories institutionnelles ---------------------------------------------------------------------------------------

it('ne crée aucune catégorie institutionnelle tant que l’installation ne les initialise pas', function () {
    expect(DB::table('institutional_categories')->count())->toBe(0)->and(DB::table('categories')->whereNotNull('institutional_code')->count())->toBe(0);
    $this->actingAs($this->admin)->get('/administration/categories-institutionnelles')->assertOk()->assertInertia(fn (Assert $p) => $p->where('initialized', false));
});

it('initialise les huit catégories avec des valeurs neutres et de façon idempotente', function () {
    $this->actingAs($this->admin)->post('/administration/categories-institutionnelles/initialiser')->assertRedirect();
    $this->post('/administration/categories-institutionnelles/initialiser')->assertRedirect();
    $rows = DB::table('institutional_categories')->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(8)->and($rows->pluck('code')->all())->toBe(['AILCT-01', 'AILCT-02', 'AILCT-03', 'AILCT-04', 'AILCT-05', 'AILCT-06', 'AILCT-07', 'AILCT-08'])
        ->and($rows->pluck('label')->all())->toBe(array_values(InstitutionalCategories::LABELS))->and($rows->pluck('color')->unique()->all())->toBe(['#64748B'])->and($rows->pluck('symbol')->unique())->toHaveCount(8);
    expect(DB::table('audit_logs')->where('action', 'categorie_institutionnelle.initialisee')->count())->toBe(1);
});

it('modifie libellé, couleur et repère d’une catégorie institutionnelle avec validation', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->actingAs($this->admin)->put('/administration/categories-institutionnelles/AILCT-03', ['label' => 'Intervenants (essai)', 'color' => '#aa3300', 'symbol' => '▲'])->assertRedirect();
    $row = DB::table('institutional_categories')->where('code', 'AILCT-03')->first();
    expect($row->label)->toBe('Intervenants (essai)')->and($row->color)->toBe('#AA3300')->and($row->symbol)->toBe('▲');
    $this->put('/administration/categories-institutionnelles/AILCT-03', ['label' => 'x', 'color' => 'rouge', 'symbol' => '▲'])->assertSessionHasErrors('color');
    $this->put('/administration/categories-institutionnelles/AILCT-09', ['label' => 'x', 'color' => '#AA3300', 'symbol' => '▲'])->assertNotFound();
    expect(json_decode(DB::table('audit_logs')->where('action', 'categorie_institutionnelle.modifiee')->value('details'), true)['champs'])->toBe(['label', 'color', 'symbol']);
});

it('associe une catégorie d’événement à une catégorie institutionnelle sans modifier aucune autorisation d’accès', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    [$event, [$catA, $catB]] = holderEvent();
    $zone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $zone, 'event_id' => null, 'name' => 'Zone fictive', 'est_accueil' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'category_id' => $catA, 'zone_id' => $zone, 'created_at' => now(), 'updated_at' => now()]);
    $zonesBefore = DB::table('category_zone')->orderBy('id')->get()->toArray();
    $link = fn (string $cat, ?string $code) => $this->actingAs($this->admin)->put('/administration/categories-institutionnelles/liaison', ['event_id' => $event, 'category_id' => $cat, 'code' => $code]);
    $link($catA, 'AILCT-01')->assertRedirect();
    expect(DB::table('categories')->where('id', $catA)->value('institutional_code'))->toBe('AILCT-01');
    $link($catB, 'AILCT-01')->assertSessionHasErrors('code');
    $link($catB, 'AILCT-02')->assertRedirect();
    $link($catA, '')->assertRedirect();
    expect(DB::table('categories')->where('id', $catA)->value('institutional_code'))->toBeNull();
    expect(DB::table('category_zone')->orderBy('id')->get()->toArray())->toEqual($zonesBefore);
    $link($catB, 'AILCT-99')->assertSessionHasErrors('code');
    $link((string) Str::uuid(), 'AILCT-01')->assertNotFound();
});

it('refuse d’associer des catégories d’un événement clos', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    [$event, [$cat]] = holderEvent();
    DB::table('events')->where('id', $event)->update(['status' => 'clos']);
    $this->actingAs($this->admin)->put('/administration/categories-institutionnelles/liaison', ['event_id' => $event, 'category_id' => $cat, 'code' => 'AILCT-01'])->assertStatus(409);
    expect(DB::table('categories')->where('id', $cat)->value('institutional_code'))->toBeNull();
});

it('ne confère aucun droit : le changement de catégorie d’un titulaire ne touche ni les zones ni les rôles', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $agent = holderUser('agent_accueil');
    $permissions = $agent->getAllPermissions()->pluck('name')->sort()->values()->all();
    $zones = DB::table('category_zone')->count();
    $id = $this->holders->create(holderData(['institutional_category' => 'AILCT-01']), $this->admin);
    $this->holders->update($id, holderData(['full_name' => DB::table('permanent_holders')->where('id', $id)->value('full_name'), 'institutional_category' => 'AILCT-04']), $this->admin);
    expect(DB::table('permanent_holders')->where('id', $id)->value('institutional_category'))->toBe('AILCT-04')->and(DB::table('category_zone')->count())->toBe($zones)
        ->and($agent->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($permissions);
    expect(fn () => $this->holders->create(holderData(['institutional_category' => 'AILCT-42']), $this->admin))->toThrow(ValidationException::class);
});

it('n’est lu par aucun service de contrôle, d’autorisation ni de badge : seuls le registre et son administration y accèdent', function () {
    // S09 : visites annoncées, véhicules et clôture du superviseur refusent les fiches de titulaires (aucune lecture du registre).
    // S10 : la page d'orientation de l'atelier compte seulement les titulaires et leurs cartes (lecture seule, aucune règle).
    // S03/S04 : le registre des personnes relie un titulaire à sa personne (historique) et refuse de clôturer la participation d'un titulaire ; ce ne sont ni des services de contrôle, ni d'autorisation, ni de badge.
    // Consolidation : les compteurs de l'espace Badges et cartes comptent les titulaires et leurs cartes (lecture seule, aucune règle ni décision).
    // Tableau de bord général : AdminOverview COMPTE les titulaires sans catégorie ou sans photo et les cartes actives, remises ou retirées (lecture seule, aucune règle) ; il n'alimente aucune décision de contrôle ni d'accès.
    // Badges et cartes : SupportPrinting (impression unique) lit le titulaire pour imprimer sa carte active, et BadgePreparerController (parcours « Préparer un badge ») liste les titulaires éligibles ; l'un et l'autre délèguent les règles à BadgeIssuance / HolderCards et n'alimentent aucune décision de contrôle ni d'accès.
    // Badges et cartes : BadgeIssuance (contrat d'émission canonique) et IssuanceLedger (registre des opérations d'émission) orchestrent l'émission des cartes permanentes ET des badges temporaires par le même contrat ; ils lisent le titulaire seulement pour l'éligibilité d'une carte et n'alimentent aucune décision de contrôle, d'autorisation ni d'accès.
    // V1 P1 : le registre unifié des personnes (PersonRegistry) compose en LECTURE SEULE l'identité, le profil permanent et les habilitations pour la consultation ; il n'alimente aucune décision de contrôle, d'autorisation ni de badge (seul PersonsController::index l'appelle).
    // Badges et cartes (convergence de l'interface) : SupportRegistry (registre commun des supports, lecture seule) relie une carte à son titulaire pour l'AFFICHER ; il n'alimente aucune décision de contrôle, d'autorisation ni d'accès.
    $allowed = ['Services/LegacyCardRegistry.php', 'Services/OperationsOverview.php', 'Services/AdminOverview.php', 'Services/SupportRegistry.php', 'Services/SupportPrinting.php', 'Http/Controllers/BadgePreparerController.php', 'Services/BadgeIssuance.php', 'Services/IssuanceLedger.php', 'Services/PersonRegistry.php', 'Services/BadgesCartesSummary.php', 'Services/InstitutionalCategories.php', 'Services/PermanentHolders.php', 'Services/Imports/HolderImport.php', 'Http/Controllers/InstitutionalCategoriesController.php', 'Http/Controllers/PermanentHoldersController.php', 'Services/HolderCards.php', 'Services/CardModels.php', 'Http/Controllers/AtelierCartesController.php', 'Http/Controllers/HolderCardsController.php', 'Services/CardLifecycle.php', 'Services/HolderEntitlements.php', 'Services/SiteEntitlements.php', 'Services/SiteMovements.php', 'Http/Controllers/HolderEntitlementsController.php', 'Services/PermanentAccess.php', 'Services/Poste/SiteControl.php', 'Console/Commands/PermanentFixtures.php', 'Services/DemoLab.php', 'Services/DataLifecycle.php', 'Services/DataRetention.php', 'Services/Maintenance.php', 'Services/Persons.php', 'Services/Participations.php', 'Services/Visits.php', 'Services/Vehicles.php', 'Services/SupervisorClosure.php', 'Http/Controllers/AtelierParcoursController.php'];
    $readers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && preg_match('/institutional_categor|institutional_code|permanent_holders|holder_id/', (string) file_get_contents($file->getPathname())) === 1) {
            $readers[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }
    // Chantier Activités : ActivityRegistry (registre des activités, lecture seule) exclut seulement les participations de titulaires (holder_id IS NULL) ; il ne lit jamais le registre permanent ni ses habilitations.
    // Outils d'invités temporaires : ils ne font que EXCLURE les fiches de participation des titulaires (holder_id IS NULL) ; ils ne lisent jamais le registre.
    $exclusionOnly = ['Services/ActivityRegistry.php', 'Services/HostGuests.php', 'Services/VisitorSearch.php', 'Services/Poste/Desk.php', 'Services/Poste/ListSnapshot.php', 'Http/Controllers/AdminVisitorsController.php', 'Http/Controllers/AtelierController.php', 'Http/Controllers/ZoneAccessController.php'];
    foreach (array_intersect($readers, $exclusionOnly) as $file) {
        $rest = preg_replace("/whereNull\\('(?:v\\.)?holder_id'\\)|whereNotNull\\('holder_id'\\)|\\b[a-z]\\.holder_id IS NULL/", '', (string) file_get_contents(app_path($file)));
        expect(preg_match('/institutional_categor|institutional_code|permanent_holders|holder_id/', (string) $rest), $file)->toBe(0);
    }
    expect(array_diff($readers, $allowed, $exclusionOnly))->toBe([]);
});

// --- Compatibilité --------------------------------------------------------------------------------------------------------------

it('laisse le contrat QR et les fiches existantes inchangés', function () {
    expect(QrSvg::PREFIX)->toBe('IKOMA1:');
    expect(DB::table('visitors')->whereNotNull('holder_id')->count())->toBe(0);
    foreach (['visitors' => 'holder_id', 'categories' => 'institutional_code'] as $table => $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue();
    }
    expect(Schema::hasColumn('events', 'is_permanent'))->toBeFalse(); // plus de contexte permanent : les cartes appartiennent au site
});

it('autorise le rattachement facultatif d’une fiche d’événement à un titulaire, une seule fois par événement', function () {
    [$event, [$cat]] = holderEvent();
    $holder = $this->holders->create(holderData(), $this->admin);
    $visitor = fn () => ['id' => (string) Str::uuid(), 'event_id' => $event, 'full_name' => 'Fiche fictive', 'category_id' => $cat, 'valid_from' => now(), 'valid_until' => now()->addDay(), 'source' => 'listé', 'status' => 'attendu', 'holder_id' => $holder, 'host_validation' => 'accepte', 'created_at' => now(), 'updated_at' => now()];
    DB::table('visitors')->insert($visitor());
    expect(fn () => DB::transaction(fn () => DB::table('visitors')->insert($visitor())))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $holder)->delete()))->toThrow(QueryException::class, 'permission denied');
});

it('protège aussi par déclencheurs, même contre le propriétaire de la base (défense en profondeur)', function () {
    $password = is_file(base_path('.secrets/db-owner')) ? trim((string) file_get_contents(base_path('.secrets/db-owner'))) : null;
    if ($password === null) {
        $this->markTestSkipped('Secret propriétaire indisponible dans cet environnement.');
    }
    config(['database.connections.owner' => [...config('database.connections.pgsql'), 'username' => 'ikoma', 'password' => $password]]);
    $owner = DB::connection('owner');
    $owner->beginTransaction();
    try {
        $id = (string) Str::uuid();
        $owner->table('permanent_holders')->insert(['id' => $id, 'identifier' => 'T-000001-0', 'identifier_seq' => 888888, 'full_name' => 'Fiche fictive propriétaire', 'status' => 'actif', 'created_at' => now(), 'updated_at' => now()]);
        $attempts = [
            'DELETE FROM permanent_holders' => 'ne se supprime pas',
            'TRUNCATE permanent_holders CASCADE' => 'ne se supprime pas',
        ];
        foreach ($attempts as $sql => $message) {
            $owner->statement('SAVEPOINT essai');
            try {
                $owner->statement($sql);
                $this->fail($sql.' aurait dû être refusé.');
            } catch (QueryException $e) {
                expect($e->getMessage())->toContain($message);
            }
            $owner->statement('ROLLBACK TO SAVEPOINT essai');
        }
    } finally {
        $owner->rollBack();
    }
});
