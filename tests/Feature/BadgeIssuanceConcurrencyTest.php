<?php

use App\Models\User;
use App\Services\Participations;
use App\Services\Persons;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Émission canonique en CONCURRENCE : de vrais processus PHP simultanés ; les données sont validées (COMMIT) puis supprimées. Données fictives uniquement.

/** @return array{event:string,category:string,participation:string,person:string,admin:User} */
function issuanceConcurrencyFixtures(): array
{
    test()->seed(RoleSeeder::class);
    $event = (string) Str::uuid();
    $category = (string) Str::uuid();
    $now = now();
    DB::table('events')->insert(['id' => $event, 'name' => 'Activité fictive de concurrence d’émission', 'starts_at' => $now->copy()->subDay(), 'ends_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Stagiaire', 'color' => '#0F5AA6', 'created_at' => $now, 'updated_at' => $now]);
    $admin = User::create(['name' => 'Administrateur fictif émission', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $admin->assignRole('administrateur');
    $person = app(Persons::class)->create($admin, ['full_name' => 'Zzconcurrence '.Str::lower(Str::random(6))]);
    $participation = app(Participations::class)->enrol($admin, $person, ['event_id' => $event, 'category_id' => $category]);

    return ['event' => $event, 'category' => $category, 'participation' => $participation, 'person' => $person, 'admin' => $admin];
}

function issuanceConcurrencyCleanup(array $f): void
{
    DB::table('badge_issuances')->where('event_id', $f['event'])->delete();
    DB::table('badges')->where('event_id', $f['event'])->delete();
    DB::table('badge_batches')->where('event_id', $f['event'])->delete();
    DB::table('visitors')->where('event_id', $f['event'])->delete();
    DB::table('categories')->where('event_id', $f['event'])->delete();
    DB::table('events')->where('id', $f['event'])->delete();
    DB::table('persons')->where('id', $f['person'])->delete();
    DB::table('model_has_roles')->where('model_id', $f['admin']->id)->delete();
    DB::table('users')->where('id', $f['admin']->id)->delete();
}

/** @return array{0: resource, 1: array<int, resource>} */
function issuanceStartChild(string ...$args): array
{
    $env = [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => 'ikoma_test', 'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => (string) DB::connection()->getConfig('password')];
    $process = proc_open([PHP_BINARY, base_path('tests/Support/badge-child.php'), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    expect($process)->not->toBeFalse();

    return [$process, $pipes];
}

/** @return array<string, mixed> */
function issuanceFinishChild(array $child): array
{
    [$process, $pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($process);
    $decoded = json_decode((string) $out, true);
    expect($decoded)->toBeArray("Sortie de l’enfant : $out");

    return $decoded;
}

it('produit un seul support quand la même opération est envoyée simultanément, et rend le même résultat aux deux demandes', function () {
    $f = issuanceConcurrencyFixtures();
    try {
        $op = (string) Str::uuid();
        $children = [issuanceStartChild('emettre', $f['participation'], $op, $f['admin']->id), issuanceStartChild('emettre', $f['participation'], $op, $f['admin']->id)];
        $results = array_map('issuanceFinishChild', $children);
        expect($results[0]['ok'])->toBeTrue()->and($results[1]['ok'])->toBeTrue()->and($results[0]['badge'])->toBe($results[1]['badge'])
            ->and(DB::table('badges')->where('printed_for_visitor_id', $f['participation'])->count())->toBe(1)
            ->and(DB::table('badge_issuances')->where('operation_id', $op)->count())->toBe(1)
            ->and(collect($results)->where('created', true)->count())->toBe(1);
    } finally {
        issuanceConcurrencyCleanup($f);
    }
});

it('refuse l’un de deux opérations distinctes simultanées pour la même participation : jamais deux supports en cours', function () {
    $f = issuanceConcurrencyFixtures();
    try {
        $children = [issuanceStartChild('emettre', $f['participation'], (string) Str::uuid(), $f['admin']->id), issuanceStartChild('emettre', $f['participation'], (string) Str::uuid(), $f['admin']->id)];
        $results = array_map('issuanceFinishChild', $children);
        expect(collect($results)->where('ok', true)->count())->toBe(1)->and(collect($results)->where('ok', false)->first()['reason'])->toBe('cycle_ouvert')
            ->and(DB::table('badges')->where('printed_for_visitor_id', $f['participation'])->count())->toBe(1)
            ->and(DB::table('badge_issuances')->where('visitor_id', $f['participation'])->count())->toBe(1);
    } finally {
        issuanceConcurrencyCleanup($f);
    }
});
