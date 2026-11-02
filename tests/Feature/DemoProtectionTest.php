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

it('ne propose ni la page, ni la génération, ni la remise à zéro en exploitation (routes introuvables côté serveur)', function () {
    $this->app['env'] = 'production';
    $event = (string) Str::uuid();
    $before = [DB::table('events')->count(), DB::table('users')->count()];
    $this->actingAs($this->admin)->withSession(['_token' => 'jeton']);
    $this->get('/administration/demonstration')->assertNotFound();
    $this->post('/administration/demonstration/generer', ['_token' => 'jeton', 'scenario' => 'evenement', 'quantity' => 20])->assertNotFound();
    $this->post('/administration/demonstration/remettre-a-zero', ['_token' => 'jeton', 'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'Motdepasse-De-Test-12345!', 'acknowledge' => true, 'clear_users' => true])->assertNotFound();
    $this->post('/administration/demonstration/'.$event.'/nettoyer', ['_token' => 'jeton', 'confirmation' => 'SUPPRIMER DEMO', 'password' => 'Motdepasse-De-Test-12345!'])->assertNotFound();
    expect([DB::table('events')->count(), DB::table('users')->count()])->toBe($before);
});

it('retire la démonstration de la navigation en exploitation et la garde dans les environnements d’essai', function () {
    $this->actingAs($this->admin);
    $this->get('/administration/identite')->assertInertia(fn (Assert $p) => $p->where('demoLabAvailable', true));
    foreach (['demo', 'local', 'testing'] as $env) {
        $this->app['env'] = $env;
        expect(DemoLab::available())->toBeTrue();
    }
    $this->app['env'] = 'production';
    $this->get('/administration/identite')->assertInertia(fn (Assert $p) => $p->where('demoLabAvailable', false));
    foreach (['production', 'staging', ''] as $env) {
        $this->app['env'] = $env;
        expect(DemoLab::available())->toBeFalse();
    }
});

it('refuse aussi au niveau du service : aucun appel interne ne contourne l’interdiction en exploitation', function () {
    $this->app['env'] = 'production';
    $lab = app(DemoLab::class);
    expect(fn () => $lab->generate('evenement', 20, $this->admin))->toThrow(HttpException::class)
        ->and(fn () => $lab->clearBusiness($this->admin, true))->toThrow(HttpException::class)
        ->and(fn () => $lab->clearScenario((string) Str::uuid(), $this->admin))->toThrow(HttpException::class);
    expect(fn () => DemoLab::authorize($this->admin))->toThrow(HttpException::class);
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

it('ne lit aucun réglage de démonstration depuis un fichier technique et réserve le laboratoire à l’administrateur authentifié à deux facteurs', function () {
    $other = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $other->assignRole('superviseur');
    $this->actingAs($other)->get('/administration/demonstration')->assertForbidden();
    auth()->logout();
    $this->get('/administration/demonstration')->assertRedirect('/login');
    $noSecondFactor = demoGuardAdmin();
    $noSecondFactor->update(['two_factor_confirmed_at' => null]);
    $this->actingAs($noSecondFactor)->get('/administration/demonstration')->assertForbidden();
});
