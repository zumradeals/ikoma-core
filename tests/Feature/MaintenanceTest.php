<?php

use App\Models\User;
use App\Services\DataLifecycle;
use App\Services\FileDeletionQueue;
use App\Services\Maintenance;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->originalConnection = config('database.connections.pgsql');
    config(['database.connections.pgsql.username' => 'ikoma', 'database.connections.pgsql.password' => trim(file_get_contents(base_path('.secrets/db-owner')))]);
    DB::purge('pgsql');
    DB::beginTransaction();
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->actor = User::create(['name' => 'Maintenance fictive L33', 'email' => Str::uuid().'@organisation.test', 'password' => 'Fictif-L33!01234', 'two_factor_confirmed_at' => now()]);
    $this->actor->assignRole('administrateur');
    $this->backupDirectory = sys_get_temp_dir().'/ikoma-backup-fictif-'.Str::uuid();
    mkdir($this->backupDirectory, 0700);
    $lines = [];
    foreach (['base' => 'dump.gpg', 'fichiers' => 'tar.gpg', 'effectifs' => 'txt'] as $type => $extension) {
        $name = 'ikoma-'.$type.'-20261030T120000Z.'.$extension;
        file_put_contents($this->backupDirectory.'/'.$name, 'Export fictif isolé '.$type);
        $lines[] = hash_file('sha256', $this->backupDirectory.'/'.$name).'  '.$name;
    }
    $this->manifest = $this->backupDirectory.'/ikoma-20261030T120000Z.sha256';
    file_put_contents($this->manifest, implode("\n", $lines)."\n");
});

afterEach(function () {
    DB::rollBack();
    DB::purge('pgsql');
    config(['database.connections.pgsql' => $this->originalConnection]);
    foreach (glob($this->backupDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->backupDirectory);
});

it('vérifie matériellement tous les exports et refuse une empreinte falsifiée', function () {
    app(Maintenance::class)->verifyBackup($this->manifest);
    file_put_contents($this->backupDirectory.'/ikoma-base-20261030T120000Z.dump.gpg', 'Altération fictive');
    expect(fn () => app(Maintenance::class)->verifyBackup($this->manifest))->toThrow(RuntimeException::class);
});

it('traite une demande bornée sur le jeu fictif et conserve son administrateur et le journal', function () {
    $service = app(Maintenance::class);
    $report = $service->preview('demo');
    $id = $service->request('demo', $this->actor, $report['fingerprint']);
    $service->execute($id, $this->manifest);
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeFalse()
        ->and(DB::table('maintenance_requests')->where('id', $id)->value('status'))->toBe('terminee')
        ->and(User::find($this->actor->id))->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'gestion.maintenance_base_validee')->exists())->toBeTrue();
    $before = DB::table('audit_logs')->where('action', 'demo.donnees_purgees')->count();
    expect(fn () => $service->execute($id, $this->manifest))->toThrow(RuntimeException::class);
    expect(DB::table('audit_logs')->where('action', 'demo.donnees_purgees')->count())->toBe($before);
});

it('refuse le nettoyage après une activité réelle ou un aperçu devenu obsolète', function () {
    $service = app(Maintenance::class);
    $id = $service->request('demo', $this->actor, app(DataLifecycle::class)->fingerprint());
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => DemoSeeder::id('visitor1'), 'type' => 'validation', 'occurred_at' => now()]);
    expect(fn () => $service->execute($id, $this->manifest))->toThrow(RuntimeException::class);
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeTrue()
        ->and(DB::table('maintenance_requests')->where('id', $id)->value('status'))->toBe('refusee');
});

it('refuse la conservation non approuvée ou un événement non clôturé', function () {
    expect(fn () => app(Maintenance::class)->preview('conservation'))->toThrow(RuntimeException::class);
    DB::table('settings')->where('key', 'conservation_validee')->update(['value' => '1']);
    DB::table('events')->where('id', DemoSeeder::id('event'))->update(['starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonths(2)]);
    expect(fn () => app(Maintenance::class)->preview('conservation'))->toThrow(RuntimeException::class);
});

it('ne laisse pas une file d’effacement détruire un fichier encore référencé', function () {
    Storage::fake('local');
    $path = 'profile-portraits/'.Str::uuid().'.enc';
    Storage::disk('local')->put($path, 'Portrait fictif');
    DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->update(['profile_photo_file' => $path]);
    app(FileDeletionQueue::class)->enqueue($path);
    expect(app(FileDeletionQueue::class)->drain())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(DB::table('pending_file_deletions')->where('path', $path)->value('attempts'))->toBe(1);
    DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->update(['profile_photo_file' => null]);
    expect(app(FileDeletionQueue::class)->drain())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});
