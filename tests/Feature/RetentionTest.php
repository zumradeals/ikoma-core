<?php

use App\Models\User;
use App\Services\Audit;
use App\Services\AuditChain;
use App\Services\AuditSeal;
use App\Services\DataRetention;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Les purges sont testées sur la connexion propriétaire, dans une transaction annulée à la fin.
// Les tests HardeningTest vérifient séparément que le compte applicatif ne peut pas exécuter ces commandes.
beforeEach(function () {
    $this->originalConnection = config('database.connections.pgsql');
    config(['database.connections.pgsql.username' => 'ikoma', 'database.connections.pgsql.password' => trim(file_get_contents(base_path('.secrets/db-owner')))]);
    DB::purge('pgsql');
    DB::beginTransaction();
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->keyDirectory = sys_get_temp_dir().'/ikoma-audit-'.Str::uuid();
    mkdir($this->keyDirectory, 0700);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    file_put_contents($this->keyDirectory.'/private.pem', $pem);
    file_put_contents($this->keyDirectory.'/public.pem', openssl_pkey_get_details($key)['key']);
    $this->travelTo('2027-06-01 10:00:00');
});
afterEach(function () {
    DB::rollBack();
    DB::purge('pgsql');
    config(['database.connections.pgsql' => $this->originalConnection]);
    foreach (glob($this->keyDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->keyDirectory);
});

it('produit un condensé signé vérifiable et détecte une signature ou une ligne changée', function () {
    app(Audit::class)->append('test.ancrage');
    $tail = app(AuditChain::class)->verify();
    $seal = app(AuditSeal::class);
    $anchor = $seal->sign(['version' => 1, 'purpose' => 'anchor', 'id' => $tail['id'], 'hash' => $tail['hash'], 'occurred_at' => $tail['occurred_at']], $this->keyDirectory.'/private.pem');
    $path = $this->keyDirectory.'/anchor.json';
    file_put_contents($path, json_encode($anchor));
    $this->artisan('audit:digest', ['--key' => $this->keyDirectory.'/private.pem'])->assertSuccessful();
    $this->artisan('audit:verify', ['--anchor' => $path, '--public-key' => $this->keyDirectory.'/public.pem'])->assertSuccessful();
    $changed = $anchor;
    $changed['payload']['hash'] = str_repeat('a', 64);
    expect(fn () => $seal->verify($changed, $this->keyDirectory.'/public.pem'))->toThrow(RuntimeException::class);
    DB::table('audit_logs')->where('id', $tail['id'])->update(['action' => 'test.modifie']);
    $this->artisan('audit:verify')->assertFailed();
});

it('vérifie cent mille lignes en moins d’une minute, sans chargement intégral', function () {
    $audit = app(Audit::class);
    $previous = DB::table('audit_logs')->orderByDesc('id')->value('hash') ?? str_repeat('0', 64);
    $at = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    for ($batch = 0; $batch < 100; $batch++) {
        $numbers = DB::select("SELECT nextval(pg_get_serial_sequence('audit_logs','id')) AS id FROM generate_series(1,1000)");
        $rows = [];
        foreach ($numbers as $number) {
            $id = (int) $number->id;
            $hash = $audit->digest($id, $previous, null, 'test.charge', $at, '{}');
            $rows[] = ['id' => $id, 'actor_id' => null, 'action' => 'test.charge', 'occurred_at' => $at, 'details' => '{}', 'previous_hash' => $previous, 'hash' => $hash];
            $previous = $hash;
        }
        DB::table('audit_logs')->insert($rows);
    }
    $start = microtime(true);
    $this->artisan('audit:verify')->assertSuccessful();
    $seconds = microtime(true) - $start;
    expect($seconds)->toBeLessThan(60);
    fwrite(STDOUT, '\nVérification de 100 000 lignes : '.round($seconds, 3).' s.\n');
});

it('refuse une purge non approuvée puis efface les identités, photos et réponses à échéance en conservant les statistiques', function () {
    Storage::fake('local');
    $event = DemoSeeder::id('event');
    $visitor = DemoSeeder::id('visitor1');
    DB::table('events')->where('id', $event)->update(['ends_at' => '2027-01-11 00:00:00+00']);
    $path = 'photos/'.Str::uuid().'.enc';
    Storage::disk('local')->put($path, 'contenu-chiffre-fictif');
    DB::table('photos')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $visitor, 'file_reference' => $path, 'taken_at' => now()->subMonths(4), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('identity_checks')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $visitor, 'checked_at' => now()]);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'visitor_id' => $visitor, 'type' => 'entree', 'occurred_at' => now()->subMonth()]);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'visitor_id' => $visitor, 'type' => 'sortie', 'occurred_at' => now()->subDay()]);
    $before = DB::table('visitors')->where('event_id', $event)->pluck('full_name')->all();
    $this->artisan('donnees:purger --confirmer')->assertFailed();
    DB::table('settings')->where('key', 'conservation_validee')->update(['value' => '1']);
    $this->artisan('donnees:purger')->assertSuccessful();
    expect(Storage::disk('local')->exists($path))->toBeTrue();
    $this->artisan('donnees:purger --confirmer')->assertSuccessful();
    expect(Storage::disk('local')->exists($path))->toBeFalse();
    expect(DB::table('photos')->where('visitor_id', $visitor)->count())->toBe(0);
    expect(DB::table('visitors')->where('event_id', $event)->count())->toBe(300);
    expect(DB::table('visitors')->where('event_id', $event)->whereNotNull('organisation')->count())->toBe(0);
    expect(DB::table('visitors')->where('event_id', $event)->whereIn('full_name', $before)->count())->toBe(0);
    expect(DB::table('access_events')->where('event_id', $event)->count())->toBe(0);
    expect(DB::table('identity_checks')->where('visitor_id', $visitor)->count())->toBe(0);
    $summary = json_decode(DB::table('events')->where('id', $event)->value('retention_summary'), true);
    expect($summary['visiteurs'])->toBe(300)->and($summary['mouvements'])->toMatchArray(['entree' => 1, 'sortie' => 1]);
    expect(DB::table('devices')->where('event_id', $event)->whereNull('revoked_at')->count())->toBe(0);
    $this->artisan('audit:verify')->assertSuccessful();
});

it('ne purge pas un événement encore actif ou avec une personne présente', function () {
    DB::table('settings')->where('key', 'conservation_validee')->update(['value' => '1']);
    $event = DemoSeeder::id('event');
    DB::table('events')->where('id', $event)->update(['ends_at' => now()->addDay()]);
    app(DataRetention::class)->purge();
    expect(DB::table('events')->where('id', $event)->value('anonymized_at'))->toBeNull();
    DB::table('events')->where('id', $event)->update(['ends_at' => now()->subYear()]);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'visitor_id' => DemoSeeder::id('visitor1'), 'type' => 'entree', 'occurred_at' => now()->subMonth()]);
    app(DataRetention::class)->purge();
    expect(DB::table('events')->where('id', $event)->value('anonymized_at'))->toBeNull();
});

it('purge uniquement un préfixe du journal avec reçu signé et refuse un faux point de reprise', function () {
    DB::table('settings')->where('key', 'conservation_validee')->update(['value' => '1']);
    DB::table('settings')->where('key', 'conservation_audit_jours')->update(['value' => '30']);
    // Les lignes préexistantes peuvent être plus récentes : créer les lignes échues après elles ne les rend pas supprimables.
    $this->travel(-40)->days();
    app(Audit::class)->append('test.expire');
    $this->travel(40)->days();
    $options = ['--key' => $this->keyDirectory.'/private.pem', '--public-key' => $this->keyDirectory.'/public.pem', '--recu' => $this->keyDirectory.'/purge.json', '--confirmer' => true];
    // Mettre l'ensemble du journal antérieur dans une période cohérente en recalculant une chaîne de test.
    $previous = str_repeat('0', 64);
    foreach (DB::table('audit_logs')->orderBy('id')->get() as $row) {
        $at = now()->subDays(40)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $hash = app(Audit::class)->digest((int) $row->id, $previous, $row->actor_id, $row->action, $at, $row->details);
        DB::table('audit_logs')->where('id', $row->id)->update(['occurred_at' => $at, 'previous_hash' => $previous, 'hash' => $hash]);
        $previous = $hash;
    }
    app(Audit::class)->append('test.recent');
    $this->artisan('audit:purger', $options)->assertSuccessful();
    expect(file_exists($this->keyDirectory.'/purge.json'))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'test.expire')->exists())->toBeFalse();
    expect(DB::table('audit_logs')->where('action', 'test.recent')->exists())->toBeTrue();
    $this->artisan('audit:verify', ['--public-key' => $this->keyDirectory.'/public.pem'])->assertSuccessful();
    $checkpoint = json_decode(DB::table('settings')->where('key', 'audit_checkpoint')->value('value'), true);
    $checkpoint['payload']['hash'] = str_repeat('0', 64);
    DB::table('settings')->where('key', 'audit_checkpoint')->update(['value' => json_encode($checkpoint)]);
    $this->artisan('audit:verify', ['--public-key' => $this->keyDirectory.'/public.pem'])->assertFailed();
});

it('réserve le bris de glace au dernier administrateur et journalise sans exposer de secret', function () {
    User::role('administrateur')->update(['active' => false]);
    $last = User::create(['name' => 'Dernier compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $last->assignRole('administrateur');
    $last->update(['two_factor_secret' => encrypt('SECRET_FICTIF'), 'two_factor_confirmed_at' => now()]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $last->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    $this->artisan('comptes:bris-de-glace '.$last->id)->assertFailed();
    $this->artisan('comptes:bris-de-glace '.$last->id.' --confirmation=REINITIALISER-2FA')->assertSuccessful();
    expect($last->fresh()->two_factor_secret)->toBeNull();
    expect(DB::table('sessions')->where('user_id', $last->id)->count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'compte.bris_de_glace')->where('details', 'like', '%'.$last->id.'%')->count())->toBe(1);
    $second = User::create(['name' => 'Autre compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $second->assignRole('administrateur');
    $this->artisan('comptes:bris-de-glace '.$last->id.' --confirmation=REINITIALISER-2FA')->assertFailed();
});
