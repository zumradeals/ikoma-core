<?php

use App\Models\User;
use App\Services\BackupStatus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function backupSetting(string $key, string $value): void
{
    DB::table('settings')->where('key', $key)->update(['value' => $value]);
}

it('refuse de créer des données de démonstration en production', function () {
    $before = DB::table('users')->where('email', 'like', '%@exemple.test')->count();
    $previous = app()->environment();
    app()['env'] = 'production';
    try {
        expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'jamais créées en production');
        expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class, 'jamais créées en production');
    } finally {
        app()['env'] = $previous;
    }
    expect(DB::table('users')->where('email', 'like', '%@exemple.test')->count())->toBe($before);
});

it('ne présente aucun bandeau fictif sur les pages publiques, même dans l’environnement historique demo', function () {
    $this->get('/login')->assertOk()->assertDontSee('bandeau-demo', false);
    $previous = app()->environment();
    app()['env'] = 'demo';
    try {
        $this->get('/login')->assertOk()->assertDontSee('bandeau-demo', false)
            ->assertDontSee('Démonstration : données fictives', false);
        $this->get('/')->assertOk()->assertDontSee('bandeau-demo', false);
    } finally {
        app()['env'] = $previous;
    }
});

it('répond sur /sante sans connexion et sans aucune donnée sensible', function () {
    $response = $this->getJson('/sante')->assertOk();
    $body = $response->json();
    expect($body['base'])->toBe('ok')->and($body['sauvegarde']['etat'])->toBe('inactif')->and(array_keys($body))->toBe(['statut', 'base', 'disque', 'sauvegarde', 'synchronisation']);
    expect(json_encode($body))->not->toMatch('/version|php|laravel|@|password|token/i');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('suit l’état de la sauvegarde : jamais, réussie, ancienne, échouée', function () {
    $status = app(BackupStatus::class);
    expect($status->state()['etat'])->toBe('inactif'); // suivi non activé : rien à surveiller en développement ou en démonstration
    backupSetting('sauvegarde_active', '1');
    expect($status->state()['etat'])->toBe('jamais');
    $this->artisan('sauvegarde:enregistrer', ['resultat' => 'ok'])->assertSuccessful();
    expect($status->state())->toMatchArray(['etat' => 'ok', 'age_heures' => 0]);
    // Le statut global dépend aussi de l'espace disque de la machine qui exécute le test : on vérifie la partie sauvegarde.
    $health = $this->getJson('/sante')->assertOk()->assertJson(['sauvegarde' => ['etat' => 'ok']])->json();
    expect($health['statut'])->toBe($health['disque']['etat'] === 'ok' ? 'ok' : 'degrade');
    $this->travel(27)->hours();
    expect($status->state()['etat'])->toBe('ancienne');
    $this->getJson('/sante')->assertOk()->assertJson(['statut' => 'degrade', 'sauvegarde' => ['etat' => 'ancienne']]);
    $this->travelBack();
    $this->artisan('sauvegarde:enregistrer', ['resultat' => 'echec'])->assertSuccessful();
    expect($status->state()['etat'])->toBe('echec');
    $this->artisan('sauvegarde:enregistrer', ['resultat' => 'peut-etre'])->assertFailed();
    $actions = DB::table('audit_logs')->whereIn('action', ['sauvegarde.reussie', 'sauvegarde.echouee'])->pluck('action')->all();
    expect($actions)->toContain('sauvegarde.reussie', 'sauvegarde.echouee');
});

it('prévient l’administrateur sur son tableau quand la sauvegarde est ancienne ou a échoué', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $admin = User::create(['name' => 'Administrateur fictif L12', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('administrateur');
    $watch = fn () => collect($this->actingAs($admin)->get('/administration')->assertOk()->viewData('page')['props']['watch'] ?? [])->pluck('title')->all();
    expect($watch())->not->toContain('Sauvegarde à vérifier'); // suivi inactif
    backupSetting('sauvegarde_active', '1');
    expect($watch())->toContain('Sauvegarde à vérifier');
    Artisan::call('sauvegarde:enregistrer', ['resultat' => 'ok']);
    expect($watch())->not->toContain('Sauvegarde à vérifier');
    Artisan::call('sauvegarde:enregistrer', ['resultat' => 'echec']);
    expect($watch())->toContain('Sauvegarde à vérifier');
});
