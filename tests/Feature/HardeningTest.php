<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Audit;
use App\Services\JournalExport;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\Devices;
use App\Services\Poste\SyncProcessor;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function hardeningUser(string $role = 'administrateur'): User
{
    $user = User::create(['name' => 'Compte fictif sécurité', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24).'aA1!']);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('protège les pages et les erreurs, réserve la caméra aux applications de terrain', function () {
    foreach (['/', '/login', '/poste/shell', '/controle/shell', '/groupe/shell', '/pilotage/evacuation/shell', '/introuvable'] as $path) {
        $response = $this->get($path);
        $policy = $response->headers->get('Content-Security-Policy');
        expect($policy)->toContain("script-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")->not->toContain('unsafe-eval');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'same-origin')->assertHeader('X-Frame-Options', 'DENY');
        expect($response->headers->get('Permissions-Policy'))->toContain('camera='.(in_array($path, ['/poste/shell', '/controle/shell', '/groupe/shell']) ? '(self)' : '()'));
    }
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('ne met aucune identité dans les quatre coquilles publiques, même connecté', function () {
    $this->actingAs(hardeningUser('agent_accueil'));
    foreach (['/poste/shell', '/controle/shell', '/groupe/shell', '/pilotage/evacuation/shell'] as $path) {
        $this->get($path)->assertInertia(fn (AssertableInertia $p) => $p->where('auth', null));
    }
});

it('journalise exactement un refus, même si middleware et rendu d’exception le rencontrent', function () {
    $actor = hardeningUser('agent_accueil');
    $before = DB::table('audit_logs')->where('action', 'acces.refuse')->count();
    $this->actingAs($actor)->get('/administration/comptes')->assertForbidden();
    expect(DB::table('audit_logs')->where('action', 'acces.refuse')->count())->toBe($before + 1);
    expect(json_decode(DB::table('audit_logs')->orderByDesc('id')->value('details'), true))->toBe(['route' => 'administration/comptes', 'statut' => 403]);
});

it('exige les droits, la préparation, le secret administrateur et un jeton à usage unique pour réinitialiser le 2FA', function () {
    $admin = hardeningUser();
    $password = Str::password(24).'aA1!';
    $admin->update(['password' => $password]);
    $target = hardeningUser('superviseur');
    $target->update(['two_factor_secret' => encrypt('SECRET_FICTIF'), 'two_factor_recovery_codes' => encrypt('[]'), 'remember_token' => 'ancien-jeton-fictif']);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $url = '/administration/comptes/'.$target->id.'/2fa/';
    $this->actingAs(hardeningUser('agent_accueil'))->post($url.'preparer')->assertForbidden();
    $this->actingAs($admin)->post('/administration/comptes/'.$admin->id.'/2fa/preparer')->assertForbidden();
    $this->post($url.'confirmer', ['token' => str_repeat('a', 64), 'password' => $password, 'confirmation' => true])->assertStatus(409);
    $this->post($url.'preparer')->assertRedirect()->assertSessionHas('twoFactorReset');
    $token = session('twoFactorReset.token');
    $this->post($url.'confirmer', ['token' => $token, 'password' => 'incorrect', 'confirmation' => true])->assertSessionHasErrors('password');
    expect($target->fresh()->two_factor_secret)->not->toBeNull();
    $this->post($url.'preparer')->assertRedirect();
    $token = session('twoFactorReset.token');
    $this->post($url.'confirmer', ['token' => $token, 'password' => $password, 'confirmation' => true])->assertRedirect();
    expect($target->fresh()->two_factor_secret)->toBeNull()->and($target->fresh()->two_factor_recovery_codes)->toBeNull()->and($target->fresh()->two_factor_confirmed_at)->toBeNull()->and($target->fresh()->remember_token)->toBeNull();
    expect(DB::table('sessions')->where('user_id', $target->id)->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'compte.2fa_reinitialisee')->where('actor_id', $admin->id)->count())->toBe(1);
    $this->travel(61)->seconds();
    $this->post($url.'confirmer', ['token' => $token, 'password' => $password, 'confirmation' => true])->assertStatus(409);
    $this->actingAs($target->fresh())->get('/espace')->assertRedirect('/securite');
});

it('expire une préparation de réinitialisation et refuse un autre destinataire', function () {
    $admin = hardeningUser();
    $target = hardeningUser('superviseur');
    $this->actingAs($admin)->post('/administration/comptes/'.$target->id.'/2fa/preparer');
    $token = session('twoFactorReset.token');
    $this->travel(301)->seconds();
    $this->post('/administration/comptes/'.$target->id.'/2fa/confirmer', ['token' => $token, 'password' => 'incorrect', 'confirmation' => true])->assertStatus(409);
});

it('invalide toutes les sessions et les jetons persistants après mot de passe, rôle et statut', function () {
    $admin = hardeningUser();
    $target = hardeningUser('agent_accueil');
    $this->actingAs($admin);
    foreach (['mot-de-passe', 'role', 'statut'] as $operation) {
        $target->forceFill(['remember_token' => Str::random(40)])->save();
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        if ($operation === 'mot-de-passe') {
            $this->put('/administration/comptes/'.$target->id.'/mot-de-passe', ['password' => Str::password(24).'aA1!'])->assertRedirect();
        } else {
            $this->put('/administration/comptes/'.$target->id, ['role' => 'hote', 'active' => $operation !== 'statut'])->assertRedirect();
        }
        expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)->and($target->fresh()->remember_token)->toBeNull();
    }
});

it('renforce progressivement les blocages et journalise IP et empreinte sans identifiant de connexion', function () {
    $email = Str::uuid().'@exemple.test';
    $token = Str::random(64);
    foreach ([60, 300, 900] as $seconds) {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-Device-Token' => $token])->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertStatus(302)->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $email, 'password' => 'incorrect'])->assertStatus(429)->assertHeader('Retry-After', (string) $seconds);
        $this->travel($seconds + 1)->seconds();
    }
    $details = DB::table('audit_logs')->where('action', 'connexion.echec')->orderByDesc('id')->value('details');
    expect($details)->not->toContain($email)->not->toContain($token);
    expect(json_decode($details, true))->toMatchArray(['ip' => '127.0.0.1', 'appareil_hash' => hash('sha256', $token)]);
});

it('neutralise toutes les amorces de formule y compris tabulation et exporte sans nom ni détail libre', function () {
    foreach (['=1+1', '+cmd', '-12', '@SUM(A1)', "\t=cmd"] as $value) {
        expect(JournalExport::cell($value))->toBe("'".$value);
    }
    expect(JournalExport::cell('Action normale'))->toBe('Action normale');
    $admin = hardeningUser();
    app(Audit::class)->append('=1+1', $admin->id, ['ancien_detail' => 'Personne entièrement fictive']);
    $url = '/administration/journal/exporter';
    $this->actingAs(hardeningUser('superviseur'))->get($url.'?debut=2026-01-01&fin=2027-12-31')->assertForbidden();
    $this->actingAs($admin)->get($url)->assertSessionHasErrors(['debut', 'fin']);
    $response = $this->get($url.'?debut='.now()->toDateString().'&fin='.now()->toDateString())->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain("'=1+1")->not->toContain('Personne entièrement fictive')->not->toContain($admin->name)->not->toContain($admin->email);
    expect(DB::table('audit_logs')->where('action', 'journal.exporte')->where('actor_id', $admin->id)->count())->toBe(1);
});

it('borne la période et la taille des exports, puis limite leur débit', function () {
    $admin = hardeningUser();
    $this->actingAs($admin);
    $this->get('/administration/journal/exporter?debut=2020-01-01&fin=2030-01-01')->assertStatus(422);
    DB::table('settings')->where('key', 'journal_export_max_lignes')->update(['value' => '1']);
    app(Audit::class)->append('test.export');
    app(Audit::class)->append('test.export');
    $url = '/administration/journal/exporter?debut='.now()->toDateString().'&fin='.now()->toDateString();
    $this->get($url)->assertStatus(422);
    $this->get($url)->assertStatus(422);
    $this->get($url)->assertStatus(429);
});

it('réserve la politique de conservation à l’administration et ne donne aucun droit de purge au compte applicatif', function () {
    $this->actingAs(hardeningUser('superviseur'))->get('/administration/conservation')->assertForbidden();
    $this->actingAs(hardeningUser())->get('/administration/conservation')->assertOk();
    $this->artisan('donnees:purger --confirmer')->assertFailed();
    $this->artisan('comptes:bris-de-glace '.auth()->id().' --confirmation=REINITIALISER-2FA')->assertFailed();
    foreach (['audit_logs', 'access_events', 'identity_checks'] as $table) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $right) {
            expect(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS allowed', [$table, $right])->allowed)->toBeFalse();
        }
    }
});

it('détecte les séquences incompatibles, révoque et alerte sans confondre un renvoi identique avec un clone', function () {
    $agent = hardeningUser('agent_accueil');
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $token = bin2hex(random_bytes(32));
    $device->update(['token_hash' => hash('sha256', $token)]);
    $operation = ['id' => (string) Str::uuid(), 'agent_id' => $agent->id, 'seq' => 1, 'type' => 'valider', 'payload' => ['visitor_id' => DemoSeeder::id('visitor1')]];
    $processor = app(SyncProcessor::class);
    $processor->process($device, $agent, [$operation], null);
    expect($processor->process($device, $agent, [$operation], null)['results'][0]['replayed'])->toBeTrue();
    $clone = [...$operation, 'id' => (string) Str::uuid()];
    expect($processor->process($device, $agent, [$clone], null))->toBe(['revoked' => true]);
    expect($device->fresh()->revoked_at)->not->toBeNull();
    expect(DB::table('sync_operations')->where('id', $clone['id'])->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'poste.clone_suspect')->count())->toBe(1);
    expect(DB::table('alerts')->where('severity', 'critique')->count())->toBe(1);
    $this->actingAs($agent)->getJson('/poste/api/liste', ['X-Device-Token' => $token])->assertUnauthorized()->assertJsonPath('code', 'appareil_revoque');
    // Un nouvel enrôlement réinitialise la séquence ; les anciens jetons restent inutilisables.
    $admin = hardeningUser();
    $code = app(Devices::class)->issueCode($device->fresh(), $admin);
    $fresh = app(Devices::class)->enrol($code, $agent);
    expect($device->fresh()->sync_seq)->toBe(0);
    $this->getJson('/poste/api/liste', ['X-Device-Token' => $token])->assertUnauthorized();
    $this->getJson('/poste/api/liste', ['X-Device-Token' => $fresh['device_token']])->assertOk();
});

it('reconstruit la photo sans métadonnées et la conserve uniquement chiffrée sans URL publique', function () {
    Storage::fake('local');
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();
    $metadata = "Exif\0\0COORDONNEES_FICTIVES";
    $withExif = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($metadata) + 2).$metadata.substr($jpeg, 2).'CONTENU_FICTIF_APRES_IMAGE';
    $agent = hardeningUser('agent_accueil');
    $device = Device::findOrFail(DemoSeeder::id('device'));
    app(Desk::class)->saveIdentity($device, $agent, DemoSeeder::id('visitor1'), true, $withExif, 'cni', 'CI0123456');
    $photo = DB::table('photos')->where('visitor_id', DemoSeeder::id('visitor1'))->first();
    expect($photo->file_reference)->toMatch('~^photos/[0-9a-f-]{36}\.enc$~');
    $cipher = Storage::disk('local')->get($photo->file_reference);
    expect($cipher)->not->toContain('Exif')->not->toContain($withExif);
    $clean = Crypt::decryptString($cipher);
    expect($clean)->not->toContain('Exif')->not->toContain('COORDONNEES_FICTIVES')->not->toContain('CONTENU_FICTIF_APRES_IMAGE');
    expect(getimagesizefromstring($clean)[0])->toBe(16);
    $this->get('/storage/'.$photo->file_reference)->assertNotFound();
    $this->get('/photos/'.$photo->id)->assertNotFound();
    expect(fn () => app(Desk::class)->saveIdentity($device, $agent, DemoSeeder::id('visitor4'), true, str_repeat('x', 1_000_001), 'cni', 'CI0123456'))->toThrow(DeskRefused::class);
});

it('limite la synchronisation et la recherche sans exécuter une requête au-delà du quota', function () {
    $agent = hardeningUser('agent_accueil');
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $token = bin2hex(random_bytes(32));
    $device->update(['token_hash' => hash('sha256', $token)]);
    $this->actingAs($agent);
    foreach (['sync' => [120, $agent->id.'|'.$device->id], 'recherche' => [240, $agent->id]] as $name => [$maximum, $identity]) {
        // Préremplir le compteur réel permet de vérifier la borne HTTP sans des centaines d'écritures sans intérêt.
        $key = md5($name.$identity);
        for ($i = 0; $i < $maximum; $i++) {
            RateLimiter::hit($key, 60);
        }
        if ($name === 'sync') {
            $this->postJson('/poste/api/sync', ['operations' => []], ['X-Device-Token' => $token])->assertStatus(429);
        } else {
            $this->getJson('/poste/api/recherche?q=Fictif', ['X-Device-Token' => $token])->assertStatus(429);
        }
    }
});

it('ne divulgue ni message libre ni trace dans les erreurs HTTP et techniques', function () {
    Log::spy();
    Route::get('/erreur-fictive-l11', fn () => throw new RuntimeException('Identité fictive à ne jamais journaliser'));
    $response = $this->getJson('/erreur-fictive-l11')->assertStatus(500);
    expect($response->getContent())->not->toContain('Identité fictive')->not->toContain('trace')->not->toContain('RuntimeException');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'Erreur applicative' && ! str_contains(json_encode($context), 'Identité fictive') && isset($context['reference']));
});
