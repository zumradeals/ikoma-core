<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function dashUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif tableau', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = dashUser('administrateur');
    $this->event = DemoSeeder::id('event');
    // Les comptes laissés par d'autres exécutions de la base de test ne comptent pas : seul l'administrateur de ce test est actif.
    DB::table('users')->where('id', '<>', $this->admin->id)->update(['active' => false]);
});

it('réserve le tableau de bord à l\'administrateur', function () {
    $this->get('/administration')->assertRedirect('/login');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(dashUser($role))->get('/administration')->assertForbidden();
    }
    $this->actingAs($this->admin)->get('/administration')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Tableau'));
    $this->actingAs($this->admin)->get('/administration?event=pas-un-identifiant')->assertSessionHasErrors('event');
});

it('calcule les étapes de préparation et les chiffres de l\'événement de démonstration', function () {
    $this->actingAs($this->admin)->get('/administration')->assertInertia(function (Assert $p) {
        $p->where('event.id', $this->event)
            ->where('steps.0.key', 'zones')->where('steps.0.status', 'fait')
            ->where('steps.1.key', 'liste')->where('steps.1.status', 'fait')
            ->where('steps.2.key', 'badges')->where('steps.2.status', 'en_cours') // le car de démonstration n'a pas ses badges
            ->where('steps.3.key', 'tablettes')->where('steps.3.status', 'a_faire') // la tablette de démonstration n'est pas enrôlée
            ->where('steps.4.key', 'groupes')
            ->where('steps.5.key', 'comptes')->where('steps.5.status', 'a_faire') // aucun compte de terrain n'existe dans ce test
            ->where('summary.total', 6)->where('summary.done', 2)
            ->where('summary.next.href', '/administration/badges')
            ->where('tiles.invites.value', 300)->where('tiles.invites.caption', 'dont 40 stagiaires en car')
            ->where('tiles.badges.total', 400)->where('tiles.badges.value', 340)
            ->where('tiles.tablettes.total', 1)->where('tiles.tablettes.value', 0);
    });
});

it('passe l\'étape des comptes au vert quand chaque rôle de terrain a un compte actif', function () {
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        dashUser($role);
    }
    $this->actingAs($this->admin)->get('/administration')->assertInertia(fn (Assert $p) => $p->where('steps.5.status', 'fait')->where('tiles.comptes.caption', 'tous les rôles sont couverts'));
    DB::table('users')->whereIn('id', User::role('hote')->pluck('id'))->update(['active' => false]);
    $this->actingAs($this->admin)->get('/administration')->assertInertia(fn (Assert $p) => $p->where('steps.5.status', 'en_cours')->where('steps.5.detail', 'Il manque : hôte'));
});

it('signale une tablette silencieuse, un code qui expire et les noms identiques', function () {
    $other = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $other, 'event_id' => $this->event, 'name' => 'Tablette fictive B', 'station' => 'Poste fictif B', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => str_repeat('a', 64), 'enrolled_at' => now()->subHours(3), 'last_synced_at' => now()->subHours(2)]);
    DB::table('devices')->where('id', $other)->update(['enrollment_hash' => str_repeat('b', 64), 'enrollment_expires_at' => now()->addMinutes(10)]);
    $this->actingAs($this->admin)->get('/administration')->assertInertia(function (Assert $p) {
        $p->where('watch', function ($watch) {
            $titles = collect($watch)->pluck('title')->all();
            expect($titles)->toContain('Une tablette est silencieuse')->toContain('Un code expire bientôt');
            expect(collect($watch)->firstWhere('title', 'Une tablette est silencieuse')['text'])->toBe('« Poste fictif A » n’a pas synchronisé depuis 2 h.');
            expect(collect($watch)->firstWhere('title', 'Un code expire bientôt')['text'])->toBe('Le code d’enrôlement de « Poste fictif B » expire dans 10 minutes.');
            expect(collect($watch)->contains(fn ($row) => str_contains($row['title'], 'identique')))->toBeTrue();

            return true;
        });
    });
});

it('n\'expose aucun nom de personne : ni dans les alertes, ni dans les dernières actions', function () {
    $this->actingAs($this->admin)->get('/administration')->assertInertia(function (Assert $p) {
        $props = $p->toArray()['props'];
        $text = json_encode([$props['watch'], $props['recent'], $props['steps'], $props['tiles']], JSON_UNESCAPED_UNICODE);
        foreach (['Yao Brou', 'Koné Mariam', 'Aimé Traoré', 'Visiteur fictif'] as $name) {
            expect($text)->not->toContain($name);
        }
        foreach ($props['recent'] as $row) {
            expect(array_keys($row))->toBe(['action', 'occurred_at']);
        }
        expect(count($props['recent']))->toBeLessThanOrEqual(5);

        return true;
    });
});

it('oriente seulement l’administrateur vers son tableau sans page-menu', function () {
    $this->actingAs($this->admin)->get('/espace')->assertRedirect('/administration');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(dashUser($role))->get('/espace')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Space')->where('auth.isAdmin', false)->where('adminContext', null));
    }
});

it('fournit le même contexte aux modules et garde les liens profonds accessibles', function () {
    foreach (['/administration', '/administration/comptes', '/administration/badges?status=Disponible&page=2', '/administration/zones', '/administration/categories', '/administration/imports', '/administration/postes', '/administration/groupes', '/administration/journal', '/administration/conservation', '/securite'] as $url) {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.isAdmin', true)->where('adminContext.event.id', $this->event));
    }
});

it('préserve les refus de permission même pour un administrateur et le journal du superviseur', function () {
    $role = \Spatie\Permission\Models\Role::findByName('administrateur');
    $role->revokePermissionTo('badges.gerer');
    $this->admin->unsetRelation('roles');
    $this->actingAs($this->admin)->get('/administration/badges')->assertForbidden();
    $this->actingAs(dashUser('superviseur'))->get('/administration/journal')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Journal')->where('auth.isAdmin', false)->where('adminContext', null));
});

it('expose uniquement le statut public de confirmation 2FA des comptes', function () {
    $this->actingAs($this->admin)->get('/administration/comptes')->assertInertia(fn (Assert $p) => $p->where('users', function ($users) {
        foreach ($users as $user) {
            expect($user)->toHaveKey('two_factor_confirmed')->not->toHaveKeys(['password', 'two_factor_secret', 'two_factor_recovery_codes']);
        }

        return true;
    }));
});
