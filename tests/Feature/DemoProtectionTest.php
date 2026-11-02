<?php

use App\Models\User;
use App\Services\DemoLab;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

function demoGuardAdmin(): User
{
    $user = User::create(['name' => 'Compte fictif démonstration', 'email' => Str::uuid().'@exemple.test', 'password' => 'Motdepasse-De-Test-12345!']);
    $user->assignRole('administrateur');
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = demoGuardAdmin();
});

it('ouvre le laboratoire à l’administrateur, y compris sur le serveur d’essai (APP_ENV=production), et garde les confirmations', function () {
    foreach (['production', 'demo', 'local', 'testing'] as $env) {
        $this->app['env'] = $env;
        expect(DemoLab::available())->toBeTrue();
        DemoLab::authorize($this->admin);
    }
    $this->app['env'] = 'production';
    $this->actingAs($this->admin)->withSession(['_token' => 'jeton']);
    $this->get('/administration/identite')->assertInertia(fn (Assert $p) => $p->where('demoLabAvailable', true));
    $this->get('/administration/demonstration')->assertOk();
    // Sans la phrase de confirmation, rien n'est effacé.
    $before = [DB::table('events')->count(), DB::table('users')->count()];
    $this->post('/administration/demonstration/remettre-a-zero', ['_token' => 'jeton', 'confirmation' => 'non', 'password' => 'Motdepasse-De-Test-12345!', 'acknowledge' => true, 'clear_users' => true])->assertSessionHasErrors('confirmation');
    expect([DB::table('events')->count(), DB::table('users')->count()])->toBe($before);
});

it('refuse le laboratoire à tout compte qui n’est pas administrateur actif avec le droit de gérer les comptes', function () {
    $this->app['env'] = 'production';
    $agent = User::create(['name' => 'Agent fictif', 'email' => Str::uuid().'@exemple.test', 'password' => 'Motdepasse-De-Test-12345!']);
    $agent->assignRole('agent_accueil');
    $lab = app(DemoLab::class);
    expect(fn () => $lab->generate('evenement', 20, $agent))->toThrow(HttpException::class)
        ->and(fn () => $lab->clearBusiness($agent, true))->toThrow(HttpException::class)
        ->and(fn () => DemoLab::authorize($agent))->toThrow(HttpException::class);
    $this->actingAs($agent)->get('/administration/demonstration')->assertForbidden();
});

it('garde les protections des titulaires et cartes permanents dans un environnement d’essai', function () {
    $this->app['env'] = 'demo';
    app(InstitutionalCategories::class)->initialize($this->admin);
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive '.Str::random(6), 'institutional_category' => 'AILCT-01'], $this->admin);
    app(HolderCards::class)->issue($holder, $this->admin);
    $counts = [DB::table('permanent_holders')->count(), DB::table('holder_cards')->count(), DB::table('events')->count()];
    $this->actingAs($this->admin)->withSession(['_token' => 'jeton'])->post('/administration/demonstration/remettre-a-zero', ['_token' => 'jeton', 'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'Motdepasse-De-Test-12345!', 'acknowledge' => true, 'clear_users' => true])->assertSessionHasErrors('confirmation');
    expect([DB::table('permanent_holders')->count(), DB::table('holder_cards')->count(), DB::table('events')->count()])->toBe($counts);
});

it('ne lit aucun réglage de démonstration depuis un fichier technique et réserve le laboratoire à l’administrateur actif', function () {
    $other = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $other->assignRole('superviseur');
    $this->actingAs($other)->get('/administration/demonstration')->assertForbidden();
    auth()->logout();
    $this->get('/administration/demonstration')->assertRedirect('/login');
    // La double authentification n'est plus exigée (désactivée pour tous les comptes) : l'administrateur actif suffit.
    $noSecondFactor = demoGuardAdmin();
    $noSecondFactor->update(['two_factor_confirmed_at' => null]);
    $this->actingAs($noSecondFactor)->get('/administration/demonstration')->assertOk();
});
