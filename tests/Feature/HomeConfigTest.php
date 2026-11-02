<?php

use App\Models\User;
use App\Services\Institution;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function homeAdmin(): User
{
    $user = User::create(['name' => 'Compte fictif accueil', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole('administrateur');

    return $user;
}

function homeInstitutionForm(array $over = []): array
{
    return array_merge(['nom' => 'AILCT', 'nom_complet' => 'Institution fictive de test', 'slogan' => '', 'accueil_titre' => 'Accueil fictif', 'accueil_description' => 'Informations publiques fictives.', 'connexion_accroche' => '', 'pied' => '', 'primary' => '#0C2D66', 'secondary' => '#1F5FBF', 'accent' => '#D6B46B'], $over);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = homeAdmin();
});

it('garde l’accueil actif pour une installation standard sans réglage', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home')->where('institution.homeEnabled', true)->where('institution.custom', false));
});

it('désactive l’accueil par défaut dès qu’une installation institutionnelle est configurée et conduit à la connexion', function () {
    $this->actingAs($this->admin)->put('/administration/identite', homeInstitutionForm())->assertRedirect();
    auth()->logout();
    $response = $this->get('/')->assertRedirect('/login');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->and($response->headers->get('Set-Cookie'))->toBeNull();
    $this->get('/login')->assertOk();
    expect(DB::table('settings')->where('key', 'institution_accueil')->exists())->toBeFalse();
});

it('active puis désactive l’accueil depuis l’administration sans rien supprimer, et journalise sans donnée personnelle', function () {
    $this->actingAs($this->admin)->put('/administration/identite', homeInstitutionForm())->assertRedirect();
    $before = DB::table('settings')->where('key', 'like', 'institution\\_%')->where('key', '<>', 'institution_accueil')->where('key', '<>', 'institution_version')->pluck('value', 'key')->all();
    $this->put('/administration/identite/accueil', ['actif' => true])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home')->where('institution.homeEnabled', true)->where('institution.homeTitle', 'Accueil fictif'));
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => false])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertRedirect('/login');
    $after = DB::table('settings')->where('key', 'like', 'institution\\_%')->where('key', '<>', 'institution_accueil')->where('key', '<>', 'institution_version')->pluck('value', 'key')->all();
    expect($after)->toBe($before);
    // Réactivation : les contenus sont retrouvés tels quels.
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => true])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('institution.homeDescription', 'Informations publiques fictives.'));
    expect(DB::table('audit_logs')->where('action', 'institution.accueil_modifie')->count())->toBe(3)->and(DB::table('audit_logs')->where('action', 'institution.accueil_modifie')->pluck('details')->implode(' '))->not->toContain('fictive');
});

it('permet de désactiver explicitement l’accueil d’une installation standard sans toucher aux autres', function () {
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => false])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertRedirect('/login');
    $this->actingAs($this->admin)->post('/administration/identite/reinitialiser', ['confirmation' => 'RESTAURER IKOMA'])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home'));
});

it('réserve le réglage à l’administrateur et le découple de l’authentification et des permissions', function () {
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $user = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
        $user->assignRole($role);
        $this->actingAs($user)->put('/administration/identite/accueil', ['actif' => true])->assertForbidden();
    }
    auth()->logout();
    $this->put('/administration/identite/accueil', ['actif' => true])->assertRedirect('/login');
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => 'peut-être'])->assertSessionHasErrors('actif');
    // Accueil désactivé : connexion, espace et administration restent inchangés.
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => false])->assertRedirect();
    $permissions = $this->admin->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();
    $this->get('/administration/identite')->assertOk();
    expect($this->admin->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($permissions);
});

it('n’expose que des informations publiques sur l’accueil, jamais de donnée opérationnelle ni de session', function () {
    $this->actingAs($this->admin)->put('/administration/identite', homeInstitutionForm())->assertRedirect();
    $this->put('/administration/identite/accueil', ['actif' => true])->assertRedirect();
    DB::table('events')->insert(['id' => (string) Str::uuid(), 'name' => 'Événement secret fictif', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    auth()->logout();
    foreach (['/', '/accueil/shell'] as $path) {
        $response = $this->get($path)->assertOk();
        $props = $response->viewData('page')['props'];
        expect(array_keys($props))->toEqualCanonicalizing(['errors', 'auth', 'flash', 'institution', 'demoLabAvailable', 'f06Active', 'adminContext'])
            ->and($props['auth'])->toBeNull()->and($props['adminContext'])->toBeNull();
        expect(json_encode($props))->not->toContain('Événement secret fictif')->and($response->getContent())->not->toContain('Événement secret fictif');
        $names = array_map(fn ($c) => $c->getName(), $response->baseResponse->headers->getCookies());
        expect($names)->not->toContain(config('session.cookie'))->and($names)->not->toContain('XSRF-TOKEN');
    }
});

it('sert toujours la coquille précachée de l’accueil, même désactivé, pour que le service worker ne puisse pas échouer', function () {
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => false])->assertRedirect();
    auth()->logout();
    $this->get('/accueil/shell')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home')->where('institution.homeEnabled', false));
});

it('isole la configuration d’accueil : elle ne vit que dans les réglages de l’installation et pas dans les fichiers techniques', function () {
    $this->actingAs($this->admin)->put('/administration/identite/accueil', ['actif' => false])->assertRedirect();
    expect(DB::table('settings')->where('key', 'institution_accueil')->value('value'))->toBe('0');
    foreach (['.env.example', 'config/app.php', 'config/ikoma.php'] as $file) {
        if (is_file(base_path($file))) {
            expect(file_get_contents(base_path($file)))->not->toContain('institution_accueil');
        }
    }
    app(Institution::class)->reset($this->admin);
    expect(DB::table('settings')->where('key', 'institution_accueil')->exists())->toBeFalse();
});
