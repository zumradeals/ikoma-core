<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Bail de poste (suppression du PIN agent) : le secret remis à l'agent connecté pour ouvrir la copie chiffrée de sa tablette.
 * Données fictives uniquement.
 */

function bailUser(string $role = 'agent_accueil'): User
{
    $user = User::create(['name' => 'Compte fictif bail', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->device = DemoSeeder::id('device');
    DB::table('devices')->where('id', $this->device)->update(['token_hash' => hash('sha256', str_repeat('7', 64)), 'revoked_at' => null]);
});

it('exige une session ouverte', function () {
    $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertUnauthorized();
});

it('refuse un compte sans droit de poste', function () {
    $this->actingAs(bailUser('superviseur'));
    $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertForbidden();
});

it('remet un secret de 32 octets stable pour le même compte et la même tablette, et trace l’émission', function () {
    $agent = bailUser();
    $this->actingAs($agent);
    $first = $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertOk()->json();
    $second = $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertOk()->json();
    expect(strlen(base64_decode($first['secret'], true)))->toBe(32)->and($second['secret'])->toBe($first['secret'])->and($first['expires_at'])->not->toBeEmpty();
    expect(DB::table('audit_logs')->where('action', 'poste.bail_emis')->where('actor_id', $agent->id)->count())->toBe(2);
});

it('remet un secret différent à un autre compte', function () {
    $this->actingAs(bailUser());
    $a = $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->json('secret');
    $this->actingAs(bailUser());
    $b = $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->json('secret');
    expect($a)->not->toBe($b);
});

it('refuse une tablette inconnue, non enrôlée ou révoquée', function () {
    $this->actingAs(bailUser());
    $this->getJson('/poste/api/coffre/bail?poste='.Str::uuid())->assertNotFound()->assertJsonPath('code', 'appareil_inconnu');
    DB::table('devices')->where('id', $this->device)->update(['revoked_at' => now()]);
    $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertNotFound();
    DB::table('devices')->where('id', $this->device)->update(['revoked_at' => null, 'token_hash' => null]);
    $this->getJson('/poste/api/coffre/bail?poste='.$this->device)->assertNotFound();
});

it('valide le paramètre poste', function () {
    $this->actingAs(bailUser());
    $this->getJson('/poste/api/coffre/bail?poste=pas-un-uuid')->assertUnprocessable();
});
