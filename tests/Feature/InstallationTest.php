<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function installationPayload(array $over = []): array
{
    return ['token' => test()->installToken, 'name' => 'Administrateur fictif', 'email' => 'admin-install@exemple.test', 'password' => test()->installPassword, 'password_confirmation' => test()->installPassword, 'event_name' => 'Événement fictif', 'site' => 'Site fictif', 'starts_at' => '2027-03-01 08:00', 'ends_at' => '2027-03-01 18:00', ...$over];
}

beforeEach(function () {
    // Valeurs aléatoires propres à chaque test : aucune constante qui ressemble à un secret dans le dépôt.
    $this->installToken = bin2hex(random_bytes(16));
    $this->installPassword = 'Mdp-'.bin2hex(random_bytes(8)).'-Aa1!';
    $this->tokenFile = tempnam(sys_get_temp_dir(), 'ikoma-install-');
    file_put_contents($this->tokenFile, $this->installToken."\n");
    // Une base de test sans administrateur ni installation terminée : on isole l'état dans la transaction du test.
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    DB::table('settings')->where('key', 'installation_terminee')->update(['value' => '0']);
    $this->audit = (int) DB::table('audit_logs')->max('id');
});

afterEach(function () {
    @unlink($this->tokenFile);
});

it('n’existe pas sans jeton d’installation (développement, démonstration)', function () {
    config(['ikoma.install_token_file' => null]);
    $this->get('/installation')->assertNotFound();
    $this->post('/installation', installationPayload())->assertNotFound();
    expect(User::count())->toBe(0);
    expect(DB::table('audit_logs')->where('id', '>', $this->audit)->where('action', 'installation.acces_refuse')->count())->toBe(2);
});

it('affiche les vérifications puis crée l’administrateur, l’événement et la zone d’accueil', function () {
    config(['ikoma.install_token_file' => $this->tokenFile]);
    $this->get('/installation')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Installation')->where('ready', true)->has('checks', 6));
    $this->post('/installation', installationPayload())->assertRedirect('/login')->assertSessionHas('status');
    $admin = User::where('email', 'admin-install@exemple.test')->firstOrFail();
    expect($admin->hasRole('administrateur'))->toBeTrue()->and($admin->two_factor_confirmed_at)->toBeNull()->and($admin->password)->not->toContain('Fictif')
        ->and($admin->requiresTwoFactor())->toBeTrue();
    $event = DB::table('events')->first();
    expect($event->name)->toBe('Événement fictif')->and(DB::table('zones')->where('event_id', $event->id)->where('est_accueil', true)->where('name', 'Accueil')->exists())->toBeTrue();
    expect(DB::table('settings')->where('key', 'installation_terminee')->value('value'))->toBe('1');
    $log = DB::table('audit_logs')->where('id', '>', $this->audit)->whereIn('action', ['installation.terminee', 'compte.cree'])->get()->map(fn ($l) => json_encode($l))->implode('');
    expect($log)->toContain('installation.terminee')->not->toContain($this->installPassword)->not->toContain($this->installToken)->not->toContain('admin-install@');
    // le premier compte est forcé à configurer le 2FA : la connexion ne mène pas à l'espace sans lui
    $this->post('/login', ['email' => 'admin-install@exemple.test', 'password' => $this->installPassword]);
    $this->get('/administration')->assertRedirect();
});

it('se verrouille après l’installation, même avec le bon jeton, et journalise les accès refusés', function () {
    config(['ikoma.install_token_file' => $this->tokenFile]);
    $this->post('/installation', installationPayload())->assertRedirect('/login');
    $this->get('/installation')->assertNotFound();
    $this->post('/installation', installationPayload(['email' => 'autre@exemple.test']))->assertNotFound();
    expect(User::where('email', 'autre@exemple.test')->exists())->toBeFalse();
    $refused = DB::table('audit_logs')->where('id', '>', $this->audit)->where('action', 'installation.acces_refuse')->get();
    expect($refused)->toHaveCount(2)->and($refused->map(fn ($l) => json_encode($l))->implode(''))->not->toContain('127.0.0.1');
});

it('refuse un mauvais jeton, un mot de passe faible et une date de fin avant le début, sans rien créer', function () {
    config(['ikoma.install_token_file' => $this->tokenFile]);
    $this->post('/installation', installationPayload(['token' => 'mauvais']))->assertSessionHasErrors('token');
    $this->post('/installation', installationPayload(['password' => 'faible', 'password_confirmation' => 'faible']))->assertSessionHasErrors('password');
    $this->post('/installation', installationPayload(['password_confirmation' => 'autre-chose']))->assertSessionHasErrors('password');
    $this->post('/installation', installationPayload(['ends_at' => '2027-02-01 08:00']))->assertSessionHasErrors('ends_at');
    expect(User::count())->toBe(0)->and(DB::table('events')->count())->toBe(0);
    expect(DB::table('settings')->where('key', 'installation_terminee')->value('value'))->toBe('0');
});

it('n’est pas disponible quand un administrateur existe déjà', function () {
    config(['ikoma.install_token_file' => $this->tokenFile]);
    $this->seed(RoleSeeder::class);
    User::create(['name' => 'Existant', 'email' => 'existant@exemple.test', 'password' => 'Mot-de-passe-Fictif-2027!'])->assignRole('administrateur');
    $this->get('/installation')->assertNotFound();
});
