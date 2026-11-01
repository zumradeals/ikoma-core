<?php

use App\Models\User;
use App\Services\Audit;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function account(string $role): User
{
    $user = User::create(['name' => 'Compte de test', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});
it('refuse administration à un agent et journalise le refus', function () {
    $user = account('agent_accueil');
    $this->actingAs($user)->get('/administration/comptes')->assertForbidden();
    expect(DB::table('audit_logs')->where('actor_id', $user->id)->where('action', 'acces.refuse')->exists())->toBeTrue();
});
it('journalise aussi un accès protégé sans connexion', function () {
    $before = DB::table('audit_logs')->where('action', 'acces.refuse')->count();
    $this->get('/administration/comptes')->assertRedirect('/login');
    expect(DB::table('audit_logs')->where('action', 'acces.refuse')->count())->toBe($before + 1);
});
it('laisse le superviseur accéder à son espace sans 2FA', function () {
    $this->actingAs(account('superviseur'))->get('/espace')->assertRedirect('/pilotage');
});
it('bloque après cinq échecs puis autorise une nouvelle tentative après le délai', function () {
    $email = Str::uuid().'@exemple.test';
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
    }
    $this->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertStatus(429);
    $this->travel(61)->seconds();
    $this->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertStatus(302)->assertSessionHasErrors('email');
});
it('n’expose pas d’inscription publique', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
it('expulse une session inactive et un compte désactivé', function () {
    $user = account('agent_accueil');
    $this->actingAs($user)->withSession(['last_interaction' => now()->subMinutes(16)->timestamp])->get('/espace')->assertRedirect('/login');
    $user->update(['active' => false]);
    $this->actingAs($user)->get('/espace')->assertRedirect('/login');
});
it('ne demande plus de challenge même si un secret OTP historique existe', function () {
    $password = Str::password(24).'aA1!';
    $user = account('superviseur');
    $secret = 'JBSWY3DPEHPK3PXP';
    $user->update(['password' => $password, 'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => now()]);
    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/espace');
    $this->assertAuthenticatedAs($user);
    $this->get('/espace')->assertRedirect('/pilotage');
    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('retire les routes Fortify pour activer ou confirmer le second facteur', function () {
    $this->actingAs(account('administrateur'))->post('/user/two-factor-authentication')->assertNotFound();
    $this->get('/two-factor-challenge')->assertNotFound();
});
it('applique la matrice exacte sans héritage implicite', function () {
    foreach (config('roles') as $role => $permissions) {
        $user = account($role);
        expect($user->can('categories.gerer'))->toBe($role === 'administrateur');
        expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe(collect($permissions)->sort()->values()->all());
    }
    expect(account('administrateur')->can('visiteurs.valider'))->toBeFalse();
    expect(account('superviseur')->can('comptes.gerer'))->toBeFalse();
});
it('réserve les droits groupes, postes et événements à l’administrateur', function () {
    foreach (['groupes.gerer', 'postes.gerer', 'evenements.gerer'] as $permission) {
        foreach (array_keys(config('roles')) as $role) {
            expect(account($role)->can($permission))->toBe($role === 'administrateur');
        }
    }
});
it('crée, attribue, désactive et réinitialise sans journaliser de secret', function () {
    $admin = account('administrateur');
    $admin->update(['two_factor_confirmed_at' => now()]);
    $email = Str::uuid().'@exemple.test';
    // Str::password(24) peut ne contenir ni chiffre ni symbole (environ 7 % des tirages) : on garantit les quatre classes exigées par la règle.
    $password = Str::password(24).'aA1!';
    $this->actingAs($admin)->post('/administration/comptes', ['name' => 'Nouveau compte fictif', 'email' => $email, 'password' => $password, 'role' => 'agent_accueil'])->assertRedirect();
    $user = User::where('email', $email)->firstOrFail();
    expect(Hash::check($password, $user->password))->toBeTrue();
    $this->put('/administration/comptes/'.$user->id, ['active' => false, 'role' => 'hote'])->assertRedirect();
    expect($user->fresh()->active)->toBeFalse();
    expect($user->fresh()->hasRole('hote'))->toBeTrue();
    $this->put('/administration/comptes/'.$user->id.'/mot-de-passe', ['password' => Str::password(24).'aA1!'])->assertRedirect();
    foreach (['compte.cree', 'compte.desactive', 'role.change', 'mot_de_passe.reinitialise'] as $action) {
        expect(DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', $action)->exists())->toBeTrue();
    }
    expect(DB::table('audit_logs')->where('details', 'like', '%'.$password.'%')->exists())->toBeFalse();
});
it('permet la lecture filtrée au superviseur sans donner la gestion des comptes', function () {
    $user = account('superviseur');
    $user->update(['two_factor_confirmed_at' => now()]);
    app(Audit::class)->append('test.filtre', $user->id);
    $this->actingAs($user)->get('/administration/journal?actor='.$user->id.'&action=test.filtre&date='.now()->toDateString())->assertOk();
    $this->get('/administration/comptes')->assertForbidden();
});
it('refuse UPDATE DELETE TRUNCATE avec le compte applicatif et détecte une altération privilégiée', function () {
    app(Audit::class)->append('test.integrite');
    $id = DB::table('audit_logs')->orderByDesc('id')->value('id');
    expect(DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname=current_user')->rolsuper)->toBeFalse();
    foreach (["UPDATE audit_logs SET action='altération' WHERE id=$id", "DELETE FROM audit_logs WHERE id=$id", 'TRUNCATE audit_logs'] as $sql) {
        try {
            DB::statement($sql);
            test()->fail('Écriture interdite acceptée');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('42501');
        }
    }
    $this->artisan('audit:verify')->assertSuccessful();
    $pdo = new PDO('pgsql:host=db;dbname=ikoma_test', 'ikoma', trim(file_get_contents(base_path('.secrets/db-owner'))));
    $original = $pdo->query("SELECT action FROM audit_logs WHERE id=$id")->fetchColumn();
    try {
        $pdo->exec("UPDATE audit_logs SET action='altération' WHERE id=$id");
        $this->artisan('audit:verify')->assertFailed();
    } finally {
        $query = $pdo->prepare('UPDATE audit_logs SET action=? WHERE id=?');
        $query->execute([$original, $id]);
    }
    $this->artisan('audit:verify')->assertSuccessful();
    try {
        $pdo->exec("UPDATE audit_logs SET id=id+1000000000 WHERE id=$id");
        $this->artisan('audit:verify')->assertFailed();
    } finally {
        $pdo->exec("UPDATE audit_logs SET id=$id WHERE id=($id+1000000000)");
    }
    $this->artisan('audit:verify')->assertSuccessful();
});

it('oriente le superviseur selon son rôle sans facteur additionnel', function () {
    $user = account('superviseur');
    $this->actingAs($user)->get('/espace')->assertRedirect('/pilotage');
    $this->get('/pilotage')->assertOk();
});
