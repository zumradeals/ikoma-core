<?php

use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\CardLifecycle;
use App\Services\DataLifecycle;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\ListSnapshot;
use App\Services\VisitorSearch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const A07_TOKEN = '7777777777777777777777777777777777777777777777777777777777777777';

function a07User(string $role): User
{
    $user = User::create(['name' => 'Compte fictif recette', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/** Événement fictif, une zone, un poste enrôlé, un titulaire habilité ayant déjà franchi l'entrée (fiche de participation créée). Retourne [event, zone, deviceId, holderId, token]. */
function a07Participation(): array
{
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif A07', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'created_at' => now(), 'updated_at' => now()]);
    $zone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $zone, 'event_id' => $event, 'name' => 'Hall fictif', 'est_accueil' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('zone_policies')->insert(['zone_id' => $zone, 'event_id' => $event, 'classification' => 'NEUTRE-TEST', 'permanent_allowed' => true, 'offline_entry_allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    $device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $device, 'event_id' => $event, 'name' => 'Poste fictif', 'station' => 'Entrée', 'point_code' => 'poste_2', 'token_hash' => hash('sha256', A07_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $admin = test()->admin;
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Titulaire Fictif Recette '.Str::random(5), 'institutional_category' => 'AILCT-01'], $admin);
    app(HolderCards::class)->issue($holder, $admin);
    app(HolderEntitlements::class)->grant($holder, $event, $zone, now()->subHour()->toIso8601String(), now()->addDay()->toIso8601String(), $admin);
    $token = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->value('b.qr_token');
    test()->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$token], ['X-Device-Token' => A07_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertOk()->assertJson(['valid' => true]);

    return [$event, $zone, $device, $holder, $token];
}

beforeEach(function () {
    // Les aperçus d'import (fichiers chiffrés) vont sur un disque factice : un test ne doit jamais laisser de fichier dans le stockage réel,
    // que les recettes navigateur partagent (un aperçu résiduel d'un autre compte bloque l'import de l'événement de démonstration).
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    $this->admin = a07User('administrateur');
    $this->agent = a07User('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->actingAs($this->agent);
});

// --- Séparation entre invités temporaires et titulaires permanents -----------------------------------------------------------

it('ne montre jamais la fiche de participation d’un titulaire dans les outils d’invités temporaires', function () {
    [$event, , $device, $holder] = a07Participation();
    $name = (string) DB::table('permanent_holders')->where('id', $holder)->value('full_name');
    $participation = (string) DB::table('visitors')->where('holder_id', $holder)->where('event_id', $event)->value('id');
    // Poste d'accueil : ni recherche, ni aperçu, ni copie locale, ni fiche.
    expect(json_encode(app(VisitorSearch::class)->preview($event)))->not->toContain($name);
    expect(json_encode(app(VisitorSearch::class)->searchDetailed($event, 'Titulaire Fictif')))->not->toContain($name);
    $poste = Device::findOrFail($device);
    expect(json_encode(app(ListSnapshot::class)->build($poste)['visitors']))->not->toContain($name);
    expect(fn () => app(Desk::class)->fiche($poste, $participation))->toThrow(DeskRefused::class);
    // Administration des invités : ni liste, ni fiche, ni modification, ni retrait.
    $this->actingAs($this->admin);
    $page = $this->get('/administration/invites?event_id='.$event)->assertOk();
    expect(json_encode($page->viewData('page')['props']['list']))->not->toContain($name);
    $this->get('/administration/invites?visitor='.$participation)->assertNotFound();
    $this->post('/administration/invites/'.$participation, ['event_id' => $event, 'full_name' => 'Renommé', 'category_id' => DB::table('visitors')->where('id', $participation)->value('category_id'), 'host_id' => null, 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 18:00', 'remove_photo' => false, 'photo_print_allowed' => false])->assertNotFound();
    $this->delete('/administration/invites/'.$participation)->assertNotFound();
    $row = DB::table('visitors')->where('id', $participation)->first();
    expect($row->full_name)->toBe($name)->and($row->withdrawn_at)->toBeNull();
});

// --- Isolation des rôles : toutes les routes protégées par une permission, côté serveur ----------------------------------------

it('refuse côté serveur, pour chaque rôle, toute route dont une permission requise lui manque (aucune réponse de succès ni de validation)', function () {
    $rows = [];
    foreach (Route::getRoutes() as $route) {
        $needed = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))->map(fn ($m) => substr($m, 4))->values()->all();
        if ($needed === []) {
            continue;
        }
        $uri = preg_replace('/\{[^}]+\}/', '00000000-0000-4000-8000-000000000000', $route->uri());
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
                continue;
            }
            $rows[] = [$method, '/'.ltrim((string) $uri, '/'), $needed];
        }
    }
    expect(count($rows))->toBeGreaterThan(100);
    // Les routes /poste/api exigent d'abord un poste enrôlé : on en fournit un pour que ce soit bien la permission qui tranche.
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif matrice', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'name' => 'Poste fictif matrice', 'point_code' => 'poste_2', 'token_hash' => hash('sha256', A07_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $headers = ['HTTP_X_DEVICE_TOKEN' => A07_TOKEN, 'HTTP_X_OPERATION_ID' => (string) Str::uuid(), 'HTTP_ACCEPT' => 'application/json'];
    $violations = [];
    foreach (['administrateur', 'superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $user = a07User($role);
        $this->actingAs($user);
        foreach ($rows as [$method, $uri, $needed]) {
            if (collect($needed)->every(fn ($p) => $user->can($p))) {
                continue;
            }
            $status = $this->call($method, $uri, [], [], [], str_starts_with($uri, '/poste/api') ? $headers : [])->getStatusCode();
            if (! in_array($status, [401, 403, 302, 404, 419, 429], true)) {
                $violations[] = "$role $method $uri → $status (exige ".implode(',', $needed).')';
            }
        }
    }
    expect($violations)->toBe([]);
});

// --- Clôture d'événement, séparation des parcours, traçabilité --------------------------------------------------------------------

it('empêche la clôture d’un événement tant qu’un titulaire permanent y est présent, sans toucher à sa carte ni à ses habilitations', function () {
    [$event, , $device, $holder, $token] = a07Participation();
    $closure = app(DataLifecycle::class);
    expect(implode(' ', $closure->closureReasons($event)))->toContain('encore présentes');
    $this->travel(2)->seconds();
    $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token], ['X-Device-Token' => A07_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertOk()->assertJson(['ok' => true]);
    expect(implode(' ', $closure->closureReasons($event)))->not->toContain('encore présentes');
    // L'événement clos refuse toute nouvelle entrée ; la carte et les habilitations du titulaire restent intactes.
    DB::table('events')->where('id', $event)->update(['status' => 'clos']);
    $this->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$token], ['X-Device-Token' => A07_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertStatus(409);
    expect(DB::table('holder_cards')->where('holder_id', $holder)->value('status'))->toBe('active')
        ->and(DB::table('holder_entitlements')->where('holder_id', $holder)->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('permanent_holders')->where('id', $holder)->value('status'))->toBe('actif');
});

it('ne modifie jamais le registre permanent depuis les parcours d’invités temporaires', function () {
    $this->seed(DemoSeeder::class);
    [$event, , , $holder] = a07Participation();
    $snapshot = fn () => md5(json_encode([DB::table('permanent_holders')->orderBy('id')->get(), DB::table('holder_cards')->orderBy('id')->get(), DB::table('holder_card_prints')->orderBy('id')->get(), DB::table('holder_entitlements')->orderBy('id')->get(), DB::table('zone_policies')->orderBy('zone_id')->get(), DB::table('institutional_categories')->orderBy('code')->get()]));
    $before = $snapshot();
    $demo = DemoSeeder::id('event');
    $this->actingAs($this->admin);
    $guest = ['event_id' => $demo, 'full_name' => 'Invité Fictif Recette', 'organisation' => 'Organisation fictive', 'position' => '', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1'), 'group_id' => null, 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 18:00', 'remove_photo' => false, 'photo_print_allowed' => false];
    $this->post('/administration/invites', $guest)->assertSessionHasNoErrors();
    // Import CSV du même nom que le titulaire : aucun effet sur le registre.
    $name = (string) DB::table('permanent_holders')->where('id', $holder)->value('full_name');
    $path = tempnam(sys_get_temp_dir(), 'recette-');
    file_put_contents($path, "nom complet;organisation;hôte;catégorie;début de validité;fin de validité\n$name;Organisation fictive;;Visiteur;;\n");
    $this->post('/administration/imports/apercu', ['event_id' => $demo, 'file' => new UploadedFile($path, 'liste-fictive.csv', null, null, true), 'create_hosts' => false]);
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    app(BadgeLots::class)->generate($demo, DemoSeeder::id('category1'), 3, $this->admin);
    expect($snapshot())->toBe($before);
});

it('journalise chaque décision de contrôle et garde une chaîne d’audit intacte après tous les parcours', function () {
    [$event, , , $holder, $token] = a07Participation();
    $headers = ['X-Device-Token' => A07_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
    app(CardLifecycle::class)->revoke($holder, 'volee', null, $this->admin);
    $this->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$token], $headers)->assertOk()->assertJson(['valid' => false, 'reason' => 'carte_volee']);
    $events = DB::table('access_events')->where('event_id', $event)->get();
    expect($events->count())->toBe(2)->and($events->pluck('type')->sort()->values()->all())->toBe(['controle', 'entree']);
    $refusal = $events->firstWhere('type', 'controle');
    expect(json_decode($refusal->details, true))->toMatchArray(['verdict' => 'refuse', 'raison' => 'carte_volee', 'permanent' => true]);
    expect(DB::table('audit_logs')->whereIn('action', ['entree.permanente', 'controle.refuse', 'carte.emise', 'carte.revoquee', 'habilitation.accordee'])->pluck('action')->unique()->sort()->values()->all())
        ->toBe(['carte.emise', 'carte.revoquee', 'controle.refuse', 'entree.permanente', 'habilitation.accordee']);
    $this->artisan('audit:verify')->assertSuccessful();
});

// --- Reprise après interruption ---------------------------------------------------------------------------------------------------

it('ne laisse pas un aperçu d’import illisible (clé changée, fichier corrompu) bloquer les imports suivants', function () {
    $this->seed(DemoSeeder::class);
    $event = DemoSeeder::id('event');
    $directory = Storage::disk('local')->path('imports/'.$event);
    File::ensureDirectoryExists($directory, 0700, true);
    file_put_contents($directory.'/preview.enc', 'eyJpdiI6ImludmFsaWRlIn0=');
    $path = tempnam(sys_get_temp_dir(), 'recette-');
    file_put_contents($path, "nom complet;organisation;hôte;catégorie;début de validité;fin de validité\nInvité Fictif Reprise;Organisation fictive;;Visiteur;;\n");
    $this->actingAs($this->admin)->post('/administration/imports/apercu', ['event_id' => $event, 'file' => new UploadedFile($path, 'liste-fictive.csv', null, null, true), 'create_hosts' => false])->assertSessionHasNoErrors();
    expect(DB::table('audit_logs')->where('action', 'liste.import_apercu_illisible')->count())->toBe(1);
    $this->get('/administration/imports?event_id='.$event)->assertOk()->assertInertia(fn ($p) => $p->where('preview.summary.importable', 1));
    File::deleteDirectory($directory);
});
