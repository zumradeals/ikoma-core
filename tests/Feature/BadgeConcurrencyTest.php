<?php

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Ces tests ouvrent de vrais processus PHP concurrents : les données doivent être validées (COMMIT), puis supprimées.

/** @return array{event:string,category:string,visitors:list<string>,agent:User,badges:list<Badge>,users:list<string>} */
function concurrencyFixtures(int $visitors = 2, int $badges = 2): array
{
    test()->seed(RoleSeeder::class);
    $event = (string) Str::uuid();
    $category = (string) Str::uuid();
    $now = now();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif de concurrence', 'starts_at' => $now->copy()->subDay(), 'ends_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Visiteur', 'color' => '#0F5AA6', 'created_at' => $now, 'updated_at' => $now]);
    $ids = [];
    for ($i = 0; $i < $visitors; $i++) {
        $ids[] = $id = (string) Str::uuid();
        DB::table('visitors')->insert(['id' => $id, 'event_id' => $event, 'full_name' => 'Visiteur fictif concurrence '.$i, 'category_id' => $category, 'valid_from' => $now->copy()->subDay(), 'valid_until' => $now->copy()->addDay(), 'source' => 'listé', 'created_at' => $now, 'updated_at' => $now]);
    }
    $agent = User::create(['name' => 'Agent fictif concurrence', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $agent->assignRole('agent_accueil');
    $admin = User::create(['name' => 'Administrateur fictif concurrence', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $admin->assignRole('administrateur');
    $batch = app(BadgeLots::class)->generate($event, $category, $badges, $admin);
    DB::table('settings')->where('key', 'badge_marge_pourcent')->exists();

    return ['event' => $event, 'category' => $category, 'visitors' => $ids, 'agent' => $agent, 'badges' => Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get()->all(), 'users' => [$agent->id, $admin->id]];
}

function concurrencyCleanup(array $f): void
{
    DB::table('badge_assignments')->where('event_id', $f['event'])->delete();
    DB::table('badges')->where('event_id', $f['event'])->delete();
    DB::table('badge_batches')->where('event_id', $f['event'])->delete();
    DB::table('visitors')->where('event_id', $f['event'])->delete();
    DB::table('categories')->where('event_id', $f['event'])->delete();
    DB::table('events')->where('id', $f['event'])->delete();
    DB::table('model_has_roles')->whereIn('model_id', $f['users'])->delete();
    DB::table('users')->whereIn('id', $f['users'])->delete();
}

/** Lance un processus enfant ; retourne [processus, tubes]. */
function startChild(string ...$args): array
{
    $env = [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => 'ikoma_test', 'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => (string) DB::connection()->getConfig('password')];
    $process = proc_open([PHP_BINARY, base_path('tests/Support/badge-child.php'), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    expect($process)->not->toBeFalse();

    return [$process, $pipes];
}

function finishChild(array $child): array
{
    [$process, $pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);
    $decoded = json_decode($out, true);
    expect($decoded)->toBeArray("Sortie de l’enfant : $out");

    return $decoded;
}

/** Attend qu’une autre session soit bloquée sur un verrou ; sans cela le test ne prouverait aucune concurrence. */
function waitForBlockedSession(int $expected = 1): void
{
    for ($i = 0; $i < 100; $i++) {
        DB::statement('SELECT pg_stat_clear_snapshot()'); // sans cela, une transaction ouverte relit toujours le même instantané
        if ((int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND datname = current_database() AND pid <> pg_backend_pid()")->n >= $expected) {
            return;
        }
        usleep(100_000);
    }
    test()->fail('Aucune session n’a été bloquée par le verrou : '.json_encode(DB::select('SELECT pid, state, wait_event_type, wait_event, left(query, 80) AS query FROM pg_stat_activity WHERE datname = current_database()')));
}

it('sérialise deux associations du même badge : le service n’en laisse réussir qu’une', function () {
    $f = concurrencyFixtures();
    try {
        $service = app(BadgeService::class);
        DB::beginTransaction();
        $service->associer($f['badges'][0], $f['visitors'][0], $f['agent']);
        $child = startChild('associer', $f['badges'][0]->id, $f['visitors'][1], $f['agent']->id);
        waitForBlockedSession();
        DB::commit();
        $result = finishChild($child);
        expect($result)->toBe(['ok' => false, 'reason' => 'deja_associe']);
        expect(DB::table('badge_assignments')->where('badge_id', $f['badges'][0]->id)->whereNull('returned_at')->whereNull('cancelled_at')->count())->toBe(1);
        expect(DB::table('badge_assignments')->where('event_id', $f['event'])->count())->toBe(1);
        expect(Badge::find($f['badges'][0]->id)->status)->toBe(BadgeStatus::Assigned);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        concurrencyCleanup($f);
    }
});

it('lance deux processus simultanés sur le même badge : une seule association aboutit', function () {
    $f = concurrencyFixtures();
    try {
        for ($round = 0; $round < 3; $round++) {
            $badge = $f['badges'][$round % 2];
            $a = startChild('associer', $badge->id, $f['visitors'][0], $f['agent']->id);
            $b = startChild('associer', $badge->id, $f['visitors'][1], $f['agent']->id);
            $results = [finishChild($a), finishChild($b)];
            $ok = array_values(array_filter($results, fn ($r) => $r['ok']));
            expect($ok)->toHaveCount(1);
            $refused = array_values(array_filter($results, fn ($r) => ! $r['ok']))[0];
            expect($refused['reason'])->toBeIn(['deja_associe', 'visiteur_deja_badge']);
            expect(DB::table('badge_assignments')->where('event_id', $f['event'])->whereNull('returned_at')->whereNull('cancelled_at')->count())->toBe(1);
            DB::table('badge_assignments')->where('event_id', $f['event'])->delete();
            DB::table('badges')->where('id', $badge->id)->update(['status' => 'Associé']); // simple remise à zéro autorisée : Associé → Restitué → Disponible
            DB::table('badges')->where('id', $badge->id)->update(['status' => 'Restitué']);
            DB::table('badges')->where('id', $badge->id)->update(['status' => 'Disponible']);
        }
    } finally {
        concurrencyCleanup($f);
    }
});

it('sérialise deux badges demandés pour le même visiteur : un seul badge actif', function () {
    $f = concurrencyFixtures();
    try {
        DB::beginTransaction();
        app(BadgeService::class)->associer($f['badges'][0], $f['visitors'][0], $f['agent']);
        $child = startChild('associer', $f['badges'][1]->id, $f['visitors'][0], $f['agent']->id);
        waitForBlockedSession();
        DB::commit();
        expect(finishChild($child))->toBe(['ok' => false, 'reason' => 'visiteur_deja_badge']);
        expect(DB::table('badge_assignments')->where('visitor_id', $f['visitors'][0])->whereNull('returned_at')->whereNull('cancelled_at')->count())->toBe(1);
        expect(Badge::find($f['badges'][1]->id)->status)->toBe(BadgeStatus::Available);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        concurrencyCleanup($f);
    }
});

it('refuse en base deux insertions simultanées contournant le service (même badge, puis même visiteur)', function () {
    $f = concurrencyFixtures(2, 3);
    try {
        $row = fn ($badge, $visitor) => ['id' => (string) Str::uuid(), 'event_id' => $f['event'], 'badge_id' => $badge, 'visitor_id' => $visitor, 'assigned_at' => now()];
        DB::beginTransaction();
        DB::table('badge_assignments')->insert($row($f['badges'][0]->id, $f['visitors'][0]));
        $sameBadge = startChild('insert', $f['badges'][0]->id, $f['visitors'][1], $f['event']);
        waitForBlockedSession();
        DB::commit();
        expect(finishChild($sameBadge))->toBe(['ok' => false, 'sqlstate' => '23505']);

        DB::beginTransaction();
        DB::table('badge_assignments')->insert($row($f['badges'][1]->id, $f['visitors'][1]));
        $sameVisitor = startChild('insert', $f['badges'][2]->id, $f['visitors'][1], $f['event']);
        waitForBlockedSession();
        DB::commit();
        expect(finishChild($sameVisitor))->toBe(['ok' => false, 'sqlstate' => '23505']);
        expect(DB::table('badge_assignments')->where('event_id', $f['event'])->count())->toBe(2);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        concurrencyCleanup($f);
    }
});

it('génère deux lots simultanément sans doublon de code dans l’événement', function () {
    $f = concurrencyFixtures(1, 1);
    try {
        $admin = User::find($f['users'][1]);
        $lots = app(BadgeLots::class);
        DB::beginTransaction();
        $lots->generate($f['event'], $f['category'], 3, $admin);
        $child = startChildLots($f);
        waitForBlockedSession();
        DB::commit();
        expect(finishChild($child))->toBe(['ok' => true]);
        $codes = DB::table('badges')->where('event_id', $f['event'])->orderBy('code')->pluck('code');
        expect($codes->count())->toBe($codes->unique()->count())->and($codes->count())->toBe(2 + 4 + 4)->and($codes->first())->toBe('B-001')->and($codes->last())->toBe('B-010');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        concurrencyCleanup($f);
    }
});

function startChildLots(array $f): array
{
    return startChild('lot', $f['category'], $f['event'], $f['users'][1]);
}
