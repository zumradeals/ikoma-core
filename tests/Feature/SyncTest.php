<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\Poste\Devices;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

const SYNC_TOKEN_A = '1111111111111111111111111111111111111111111111111111111111111111';
const SYNC_TOKEN_B = '2222222222222222222222222222222222222222222222222222222222222222';

function syncUser(string $role = 'agent_accueil'): User
{
    $user = User::create(['name' => 'Compte fictif L6', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function syncHeaders(string $token = SYNC_TOKEN_A): array
{
    return ['X-Device-Token' => $token, 'Accept' => 'application/json'];
}

/** Une opération de la file locale, telle que la tablette l'envoie. */
function syncOp(string $type, array $payload, User $agent, int $seq, ?string $id = null, array $extra = []): array
{
    return ['id' => $id ?? (string) Str::uuid(), 'seq' => $seq, 'type' => $type, 'agent_id' => $agent->id, 'client_time' => now()->toIso8601String(), 'payload' => $payload, ...$extra];
}

function syncPhoto(): string
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNTAK/9sAQwAQCwwODAoQDg0OEhEQExgoGhgWFhgxIyUdKDozPTw5Mzg3QEhcTkBEV0U3OFBtUVdfYmdoZz5NcXlwZHhcZWdj/9sAQwEREhIYFRgvGhovY0I4QmNjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2Nj/8AAEQgAEAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8Ax6KKK9U84//Z');

    return base64_encode($jpeg);
}

function syncVisitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

/** Opérations d'un parcours complet hors ligne (identité, validation, badge, entrée) pour un visiteur du seed. */
function syncFlow(string $visitor, string $badgeCode, User $agent, int &$seq, ?string $avis = null): array
{
    return [
        syncOp('identite', ['visitor_id' => $visitor, 'identity_checked' => true, 'photo_b64' => syncPhoto()], $agent, ++$seq),
        syncOp('valider', ['visitor_id' => $visitor, 'avis' => $avis], $agent, ++$seq),
        syncOp('badge', ['visitor_id' => $visitor, 'code' => $badgeCode], $agent, ++$seq),
        syncOp('entree', ['visitor_id' => $visitor, 'duration_ms' => 30000], $agent, ++$seq),
    ];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->agent = syncUser();
    $this->actingAs($this->agent);
    $this->deviceA = DemoSeeder::id('device');
    $this->deviceB = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $this->deviceB, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette fictive B', 'station' => 'Poste fictif B', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->where('id', $this->deviceA)->update(['token_hash' => hash('sha256', SYNC_TOKEN_A)]);
    DB::table('devices')->where('id', $this->deviceB)->update(['token_hash' => hash('sha256', SYNC_TOKEN_B)]);
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 12, syncUser('administrateur'));
    $this->badges = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get();
});

function syncAudit(): Builder
{
    return DB::table('audit_logs')->where('id', '>', test()->auditStart);
}

it('refuse un appareil sans jeton, non enrôlé ou révoqué, sur la liste comme sur la synchronisation', function () {
    $this->getJson('/poste/api/liste')->assertStatus(422)->assertJson(['code' => 'poste_requis']);
    $this->postJson('/poste/api/sync', ['operations' => []], [])->assertStatus(422);
    $unknown = str_repeat('c', 64);
    $this->getJson('/poste/api/liste', syncHeaders($unknown))->assertStatus(401)->assertJson(['code' => 'appareil_non_enrole']);
    $this->postJson('/poste/api/sync', ['operations' => [syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, 1)]], syncHeaders($unknown))->assertStatus(401)->assertJson(['code' => 'appareil_non_enrole']);
    $this->getJson('/poste/api/liste', syncHeaders('pas-un-jeton'))->assertStatus(401);
    expect(DB::table('sync_operations')->count())->toBe(0);
    // révocation : refus immédiat, ordre d'effacement journalisé une seule fois
    app(Devices::class)->revoke(Device::findOrFail($this->deviceA), syncUser('administrateur'));
    $this->getJson('/poste/api/liste', syncHeaders())->assertStatus(401)->assertJson(['code' => 'appareil_revoque']);
    $this->postJson('/poste/api/sync', ['operations' => [syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, 1)]], syncHeaders())->assertStatus(401)->assertJson(['code' => 'appareil_revoque']);
    expect(syncAudit()->where('action', 'poste.efface')->count())->toBe(1)->and(syncAudit()->where('action', 'poste.revoque')->count())->toBe(1)->and(DB::table('sync_operations')->count())->toBe(0);
});

it('réserve la liste, l’enrôlement et la synchronisation aux agents d’accueil', function () {
    foreach (['administrateur', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(syncUser($role));
        $this->getJson('/poste/api/liste', syncHeaders())->assertForbidden();
        $this->postJson('/poste/api/sync', ['operations' => []], syncHeaders())->assertForbidden();
        $this->postJson('/poste/api/enroler', ['code' => 'AAAA-AAAA-AAAA'], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson('/poste/api/efface', ['motif' => 'manuel'], syncHeaders())->assertForbidden();
    }
});

it('enrôle une tablette avec un code à usage unique de 15 minutes et ne stocke que des hachages', function () {
    $devices = app(Devices::class);
    $device = Device::findOrFail($this->deviceB);
    DB::table('devices')->where('id', $device->id)->update(['token_hash' => null]);
    $code = $devices->issueCode($device, syncUser('administrateur'));
    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/');
    $stored = DB::table('devices')->where('id', $device->id)->first();
    expect($stored->enrollment_hash)->toBe(Devices::hash($code))->and($stored->enrollment_hash)->not->toContain(str_replace('-', '', $code));
    $this->postJson('/poste/api/enroler', ['code' => strtolower($code)], ['Accept' => 'application/json'])->assertOk()->assertJsonStructure(['device_token', 'device_secret', 'poste' => ['id', 'name', 'event_id', 'event_name']]);
    $token = $this->postJson('/poste/api/enroler', ['code' => $code])->assertStatus(422)->assertJson(['code' => 'code_invalide']);
    // premier échange : relire le jeton renvoyé par la première réponse pour vérifier le hachage
    $device->refresh();
    expect($device->token_hash)->toMatch('/^[0-9a-f]{64}$/')->and($device->enrollment_hash)->toBeNull()->and($device->enrolled_at)->not->toBeNull();
    $second = $devices->issueCode($device, syncUser('administrateur'));
    $this->travelTo(now()->addMinutes(16));
    $this->withSession(['last_interaction' => now()->timestamp])->postJson('/poste/api/enroler', ['code' => $second])->assertStatus(422)->assertJson(['code' => 'code_invalide']);
    expect(syncAudit()->where('action', 'poste.enrole')->count())->toBe(1);
});

it('ne journalise ni le code, ni le jeton, ni le secret d’appareil', function () {
    $device = Device::findOrFail($this->deviceB);
    $code = app(Devices::class)->issueCode($device, syncUser('administrateur'));
    $response = $this->postJson('/poste/api/enroler', ['code' => $code])->assertOk();
    $token = $response->json('device_token');
    $secret = $response->json('device_secret');
    expect($token)->toMatch('/^[0-9a-f]{64}$/');
    $this->getJson('/poste/api/liste', ['X-Device-Token' => $token])->assertOk();
    $this->postJson('/poste/api/sync', ['operations' => [syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, 1)]], ['X-Device-Token' => $token])->assertOk();
    foreach ([DB::table('audit_logs')->pluck('details')->all(), DB::table('sync_operations')->pluck('result')->all(), DB::table('sync_conflicts')->pluck('details')->all()] as $rows) {
        foreach ($rows as $row) {
            expect((string) $row)->not->toContain($token)->and((string) $row)->not->toContain($secret)->and((string) $row)->not->toContain(str_replace('-', '', $code))->and((string) $row)->not->toContain($code);
        }
    }
    expect(DB::table('devices')->where('id', $device->id)->value('token_hash'))->toBe(hash('sha256', $token));
    $this->postJson('/poste/api/efface', ['motif' => 'manuel'], ['X-Device-Token' => $token])->assertOk();
    expect(syncAudit()->where('action', 'poste.efface')->count())->toBe(1);
    $this->postJson('/poste/api/efface', ['motif' => 'inconnu'], ['X-Device-Token' => $token])->assertStatus(422);
});

it('bloque les essais de code après cinq échecs', function () {
    RateLimiter::clear('poste-enrol:'.$this->agent->id);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/poste/api/enroler', ['code' => 'ZZZZ-ZZZZ-ZZZZ'])->assertStatus(422)->assertJson(['code' => 'code_invalide']);
    }
    $this->postJson('/poste/api/enroler', ['code' => 'ZZZZ-ZZZZ-ZZZZ'])->assertStatus(429)->assertJson(['code' => 'trop_d_essais']);
    RateLimiter::clear('poste-enrol:'.$this->agent->id);
});

it('envoie la copie de travail sans jeton QR en clair, avec tous les badges de l’événement (L7) dont seuls les badges réservés au poste sont marqués « mine »', function () {
    $number = (int) substr($this->badges[0]->code, 2);
    app(BadgeLots::class)->reserve($this->badges[0]->badge_batch_id, $number, $number + 2, Device::findOrFail($this->deviceA), syncUser('administrateur'));
    $liste = $this->getJson('/poste/api/liste', syncHeaders())->assertOk()->json();
    expect($liste['visitors'])->toHaveCount(300)->and($liste['categories'])->toHaveCount(4)->and($liste['hosts'])->toHaveCount(3)->and($liste['settings'])->toBe(['inactivity_minutes' => 5, 'clock_skew_seconds' => 300, 'pin_max_failures' => 15, 'controle_stagiaires' => 'encadreur']);
    expect(array_keys($liste['visitors'][0]))->toBe(['id', 'full_name', 'organisation', 'host_id', 'category_id', 'valid_from', 'valid_until', 'source', 'status', 'host_validation', 'access_note', 'group_id', 'a_rebadger', 'rev', 'identity_done', 'photo_done', 'validated', 'badge_code']);
    $codes = array_column($liste['badges'], 'code');
    expect($codes)->toContain($this->badges[0]->code)->toContain($this->badges[2]->code)->toContain($this->badges[3]->code);
    $mine = collect($liste['badges'])->where('mine', true)->pluck('code');
    expect($mine)->toContain($this->badges[0]->code)->toContain($this->badges[2]->code)->not->toContain($this->badges[3]->code);
    $token = DB::table('badges')->where('id', $this->badges[0]->id)->value('qr_token');
    $body = json_encode($liste);
    expect($body)->not->toContain($token)->and($liste['badges'][array_search($this->badges[0]->code, $codes, true)]['token_hash'])->toBe(hash('sha256', $token));
    // le lot réservé de démonstration (60 badges) reste attaché à ce poste
    expect($mine->count())->toBe(60 + 3)->and(count($liste['badges']))->toBe(DB::table('badges')->where('event_id', DemoSeeder::id('event'))->count());
    $version = $this->getJson('/poste/api/liste/version', syncHeaders())->assertOk()->json('version');
    expect($version)->toBe($liste['version'])->toMatch('/^[0-9a-f]{40}$/');
    DB::table('visitors')->where('id', syncVisitor(1))->update(['organisation' => 'Autre organisation fictive', 'updated_at' => now()->addSecond()]);
    expect($this->getJson('/poste/api/liste/version', syncHeaders())->json('version'))->not->toBe($version);
});

it('applique une file hors ligne dans l’ordre, une seule fois, et la rejoue sans aucun effet', function () {
    $seq = 0;
    $ops = [...syncFlow(syncVisitor(1), $this->badges[0]->code, $this->agent, $seq), ...syncFlow(syncVisitor(4), $this->badges[1]->code, $this->agent, $seq)];
    $response = $this->postJson('/poste/api/sync', ['sent_at' => now()->toIso8601String(), 'operations' => array_reverse($ops)], syncHeaders())->assertOk();
    expect($response->json('summary'))->toBe(['received' => 8, 'applied' => 8, 'conflicts' => 0]);
    expect(array_column($response->json('results'), 'status'))->toBe(array_fill(0, 8, 'applique'));
    $count = fn () => [DB::table('access_events')->count(), DB::table('photos')->count(), DB::table('identity_checks')->count(), DB::table('badge_assignments')->count(), DB::table('sync_operations')->count(), DB::table('audit_logs')->count() - 1];
    $before = $count();
    expect($before)->toBe([4, 2, 2, 2, 8, $before[5]]);
    expect(DB::table('visitors')->whereIn('id', [syncVisitor(1), syncVisitor(4)])->pluck('status')->unique()->all())->toBe(['present']);
    // la même file envoyée une seconde fois, puis encore, ne change rien
    foreach ([1, 2] as $_) {
        $again = $this->postJson('/poste/api/sync', ['sent_at' => now()->toIso8601String(), 'operations' => $ops], syncHeaders())->assertOk();
        expect($again->json('summary'))->toBe(['received' => 8, 'applied' => 8, 'conflicts' => 0])->and(collect($again->json('results'))->every(fn ($r) => $r['replayed'] ?? false))->toBeTrue();
    }
    $after = $count();
    expect(array_slice($after, 0, 5))->toBe(array_slice($before, 0, 5));
    expect(DB::table('devices')->where('id', $this->deviceA)->value('last_synced_at'))->not->toBeNull();
});

it('reprend correctement après une coupure en plein envoi', function () {
    $seq = 0;
    $ops = syncFlow(syncVisitor(1), $this->badges[0]->code, $this->agent, $seq);
    // la connexion tombe après les deux premières opérations : le serveur les a appliquées, la tablette ne le sait pas
    $this->postJson('/poste/api/sync', ['operations' => array_slice($ops, 0, 2)], syncHeaders())->assertOk();
    $resume = $this->postJson('/poste/api/sync', ['operations' => $ops], syncHeaders())->assertOk();
    expect(array_column($resume->json('results'), 'status'))->toBe(['applique', 'applique', 'applique', 'applique'])->and(array_column($resume->json('results'), 'replayed'))->toBe([true, true]);
    expect(DB::table('access_events')->where('visitor_id', syncVisitor(1))->count())->toBe(2)->and(DB::table('badge_assignments')->where('visitor_id', syncVisitor(1))->count())->toBe(1)->and(DB::table('photos')->where('visitor_id', syncVisitor(1))->count())->toBe(1);
    // un identifiant réutilisé avec une autre charge n'est jamais appliqué
    $forged = [...$ops[3], 'payload' => ['visitor_id' => syncVisitor(4), 'duration_ms' => 1]];
    $conflict = $this->postJson('/poste/api/sync', ['operations' => [$forged]], syncHeaders())->assertOk()->json('results.0');
    expect($conflict['code'])->toBe('operation_conflit')->and(DB::table('visitors')->where('id', syncVisitor(4))->value('status'))->toBeNull();
});

it('met en conflit, sans rien écraser, deux postes hors ligne qui associent le même badge', function () {
    $badge = $this->badges[5]; // hors lot réservé : il n'est réservé à aucun poste
    $seqA = 0;
    $seqB = 0;
    $opsA = [syncOp('identite', ['visitor_id' => syncVisitor(1), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, ++$seqA), syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, ++$seqA), syncOp('badge', ['visitor_id' => syncVisitor(1), 'code' => $badge->code], $this->agent, ++$seqA), syncOp('entree', ['visitor_id' => syncVisitor(1)], $this->agent, ++$seqA)];
    $opsB = [syncOp('identite', ['visitor_id' => syncVisitor(4), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, ++$seqB), syncOp('valider', ['visitor_id' => syncVisitor(4)], $this->agent, ++$seqB), syncOp('badge', ['visitor_id' => syncVisitor(4), 'code' => $badge->code], $this->agent, ++$seqB), syncOp('entree', ['visitor_id' => syncVisitor(4)], $this->agent, ++$seqB),
        syncOp('identite', ['visitor_id' => syncVisitor(7), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, ++$seqB)];
    $this->postJson('/poste/api/sync', ['operations' => $opsA], syncHeaders(SYNC_TOKEN_A))->assertOk()->assertJsonPath('summary.conflicts', 0);
    $b = $this->postJson('/poste/api/sync', ['operations' => $opsB], syncHeaders(SYNC_TOKEN_B))->assertOk();
    expect($b->json('summary'))->toBe(['received' => 5, 'applied' => 3, 'conflicts' => 2]);
    expect($b->json('results.2'))->toMatchArray(['status' => 'conflit', 'code' => 'deja_associe'])->and($b->json('results.3.code'))->toBe('badge_requis')->and($b->json('results.4.status'))->toBe('applique');
    // rien n'est écrasé : le badge reste au premier visiteur, le second n'a ni badge ni entrée, les autres opérations de la file ont continué
    expect(DB::table('badge_assignments')->where('badge_id', $badge->id)->pluck('visitor_id')->all())->toBe([syncVisitor(1)])
        ->and(DB::table('visitors')->where('id', syncVisitor(4))->value('status'))->toBeNull()->and(DB::table('photos')->where('visitor_id', syncVisitor(7))->count())->toBe(1);
    $conflicts = DB::table('sync_conflicts')->get();
    expect($conflicts)->toHaveCount(2)->and($conflicts->pluck('reason')->sort()->values()->all())->toBe(['badge_requis', 'deja_associe'])->and($conflicts->pluck('status')->unique()->all())->toBe(['ouvert'])->and($conflicts->pluck('device_id')->unique()->all())->toBe([$this->deviceB]);
    expect(syncAudit()->where('action', 'sync.conflit')->count())->toBe(2)->and(syncAudit()->where('action', 'sync.recue')->count())->toBe(2);
});

it('refuse une seconde entrée venue d’un autre poste et signale la fiche modifiée entre-temps', function () {
    $seq = 0;
    $a = syncFlow(syncVisitor(1), $this->badges[0]->code, $this->agent, $seq);
    $this->postJson('/poste/api/sync', ['operations' => $a], syncHeaders(SYNC_TOKEN_A))->assertOk();
    $late = syncOp('entree', ['visitor_id' => syncVisitor(1), 'duration_ms' => 5000], $this->agent, 1);
    expect($this->postJson('/poste/api/sync', ['operations' => [$late]], syncHeaders(SYNC_TOKEN_B))->json('results.0.code'))->toBe('deja_present');
    expect(DB::table('access_events')->where('visitor_id', syncVisitor(1))->where('type', 'entree')->count())->toBe(1);
    // fiche modifiée depuis la copie de la tablette B (révision plus ancienne que la fiche)
    $stale = syncOp('identite', ['visitor_id' => syncVisitor(4), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, 2, null, ['visitor_rev' => now()->subHour()->toIso8601String()]);
    DB::table('visitors')->where('id', syncVisitor(4))->update(['updated_at' => now()]);
    expect($this->postJson('/poste/api/sync', ['operations' => [$stale]], syncHeaders(SYNC_TOKEN_B))->json('results.0.code'))->toBe('fiche_modifiee');
    expect(DB::table('photos')->where('visitor_id', syncVisitor(4))->count())->toBe(0);
    $fresh = syncOp('identite', ['visitor_id' => syncVisitor(7), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, 3, null, ['visitor_rev' => now()->addMinute()->toIso8601String()]);
    expect($this->postJson('/poste/api/sync', ['operations' => [$fresh]], syncHeaders(SYNC_TOKEN_B))->json('results.0.status'))->toBe('applique');
});

it('met en quarantaine les opérations d’un agent désactivé entre-temps, sans bloquer les autres', function () {
    $other = syncUser();
    $seq = 0;
    $mine = syncOp('identite', ['visitor_id' => syncVisitor(1), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, ++$seq);
    $theirs = syncOp('identite', ['visitor_id' => syncVisitor(4), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $other, ++$seq);
    $other->update(['active' => false]);
    $wrongRole = syncOp('valider', ['visitor_id' => syncVisitor(7)], syncUser('hote'), ++$seq);
    $unknown = syncOp('valider', ['visitor_id' => syncVisitor(7)], $this->agent, ++$seq, null, ['agent_id' => (string) Str::uuid()]);
    $response = $this->postJson('/poste/api/sync', ['operations' => [$theirs, $mine, $wrongRole, $unknown]], syncHeaders())->assertOk();
    $byId = collect($response->json('results'))->keyBy('id');
    expect($byId[$mine['id']]['status'])->toBe('applique')->and($byId[$theirs['id']])->toMatchArray(['status' => 'conflit', 'code' => 'agent_desactive'])->and($byId[$wrongRole['id']]['code'])->toBe('agent_desactive')->and($byId[$unknown['id']]['code'])->toBe('agent_inconnu');
    expect(DB::table('photos')->where('visitor_id', syncVisitor(4))->count())->toBe(0)->and(DB::table('photos')->where('visitor_id', syncVisitor(1))->count())->toBe(1)->and(DB::table('sync_conflicts')->where('reason', 'agent_desactive')->count())->toBe(2);
});

it('signale l’écart d’horloge au-delà du seuil et conserve l’heure déclarée', function () {
    $seq = 0;
    $ops = syncFlow(syncVisitor(1), $this->badges[0]->code, $this->agent, $seq);
    $declared = now()->subMinutes(2)->toIso8601String();
    $ops[1]['client_time'] = $declared;
    $this->postJson('/poste/api/sync', ['sent_at' => now()->subMinutes(2)->toIso8601String(), 'operations' => $ops], syncHeaders())->assertOk()->assertJsonPath('clock_skew_seconds', 120);
    expect(DB::table('sync_conflicts')->where('kind', 'horloge')->count())->toBe(0);
    expect(Carbon::parse(json_decode(DB::table('access_events')->where('visitor_id', syncVisitor(1))->where('type', 'validation')->value('details'), true)['heure_declaree'])->equalTo(Carbon::parse($declared)))->toBeTrue();
    $late = syncOp('identite', ['visitor_id' => syncVisitor(4), 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, 9);
    $this->postJson('/poste/api/sync', ['sent_at' => now()->subMinutes(20)->toIso8601String(), 'operations' => [$late]], syncHeaders())->assertOk()->assertJsonPath('clock_skew_seconds', 1200)->assertJsonPath('summary.applied', 1);
    $row = DB::table('sync_conflicts')->where('kind', 'horloge')->first();
    expect($row->reason)->toBe('horloge_suspecte')->and(json_decode($row->details, true))->toBe(['ecart_secondes' => 1200, 'seuil_secondes' => 300])->and($row->operation_id)->toBeNull();
    expect(json_decode(syncAudit()->where('action', 'sync.recue')->orderByDesc('id')->value('details'), true)['ecart_horloge_s'])->toBe(1200);
});

it('crée hors ligne un visiteur non listé avec un identifiant de la tablette et le bloque jusqu’à l’accord de l’hôte', function () {
    $new = (string) Str::uuid();
    $seq = 0;
    $ops = [
        syncOp('non_liste', ['visitor_id' => $new, 'name' => 'Personne fictive Imprévue', 'organisation' => 'Société fictive', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1')], $this->agent, ++$seq),
        syncOp('identite', ['visitor_id' => $new, 'identity_checked' => true, 'photo_b64' => syncPhoto()], $this->agent, ++$seq),
        syncOp('valider', ['visitor_id' => $new], $this->agent, ++$seq),
    ];
    $results = $this->postJson('/poste/api/sync', ['operations' => $ops], syncHeaders())->assertOk()->json('results');
    expect(array_column($results, 'status'))->toBe(['applique', 'applique', 'conflit'])->and($results[2]['code'])->toBe('hote_en_attente');
    expect(DB::table('visitors')->where('id', $new)->first())->toMatchObject(['source' => 'non listé', 'host_validation' => 'en_attente']);
    expect(DB::table('access_events')->where('visitor_id', $new)->count())->toBe(0);
    // la tablette rejoue sa création : pas de doublon
    $this->postJson('/poste/api/sync', ['operations' => [$ops[0]]], syncHeaders())->assertOk()->assertJsonPath('results.0.replayed', true);
    expect(DB::table('visitors')->where('source', 'non listé')->count())->toBe(1);
});

it('valide la forme des envois et limite leur taille', function () {
    $this->postJson('/poste/api/sync', [], syncHeaders())->assertStatus(422);
    $this->postJson('/poste/api/sync', ['operations' => []], syncHeaders())->assertStatus(422);
    $tooMany = array_map(fn ($i) => syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, $i), range(1, 51));
    $this->postJson('/poste/api/sync', ['operations' => $tooMany], syncHeaders())->assertStatus(422);
    $invalid = $this->postJson('/poste/api/sync', ['operations' => [['id' => 'x', 'type' => 'inconnu', 'payload' => []], syncOp('valider', ['visitor_id' => syncVisitor(1)], $this->agent, 1)]], syncHeaders())->assertOk();
    expect($invalid->json('results.0.code'))->toBe('operation_invalide')->and($invalid->json('results.1.code'))->toBe('identite_requise')->and($invalid->json('summary'))->toBe(['received' => 2, 'applied' => 0, 'conflicts' => 2]);
});

it('gère les postes depuis l’administration : création, code affiché une fois, révocation, réglages', function () {
    $admin = syncUser('administrateur');
    $this->actingAs($admin);
    $event = DemoSeeder::id('event');
    foreach (['superviseur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(syncUser($role))->get('/administration/postes')->assertForbidden();
    }
    $this->actingAs($admin)->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive C', 'station' => 'Poste fictif C'])->assertRedirect();
    $device = DB::table('devices')->where('name', 'Tablette fictive C')->first();
    $this->post('/administration/postes/'.$device->id.'/code')->assertRedirect();
    $page = $this->get('/administration/postes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Postes')->where('newCode.device_id', $device->id)->has('newCode.code')->has('postes', 3)->where('settings.inactivity', 5));
    $code = $page->inertiaProps('newCode.code');
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');
    $this->get('/administration/postes')->assertInertia(fn (AssertableInertia $p) => $p->where('newCode', null)); // affiché une seule fois
    expect(DB::table('devices')->where('id', $device->id)->value('enrollment_hash'))->not->toContain(str_replace('-', '', $code));
    $this->post('/administration/postes/'.$device->id.'/revoquer')->assertRedirect();
    expect(DB::table('devices')->where('id', $device->id)->value('revoked_at'))->not->toBeNull();
    $this->put('/administration/postes/reglages', ['inactivity' => 10, 'skew' => 600, 'pin' => 12])->assertRedirect();
    expect(DB::table('settings')->whereIn('key', ['poste_inactivite_minutes', 'poste_ecart_horloge_secondes', 'poste_echecs_pin_max'])->orderBy('key')->pluck('value', 'key')->all())->toEqual(['poste_echecs_pin_max' => '12', 'poste_ecart_horloge_secondes' => '600', 'poste_inactivite_minutes' => '10']);
    $this->put('/administration/postes/reglages', ['inactivity' => 0, 'skew' => 600, 'pin' => 12])->assertSessionHasErrors('inactivity');
    // seuils des alertes du contrôle (audit global B2, B3) : réglables, bornés, journalisés
    $this->put('/administration/postes/reglages', ['inactivity' => 10, 'skew' => 600, 'pin' => 12, 'pointsDelay' => 45, 'burstCount' => 7, 'burstMinutes' => 3])->assertRedirect();
    expect(DB::table('settings')->whereIn('key', ['controle_delai_min_points', 'controle_rafale_nombre', 'controle_rafale_minutes'])->orderBy('key')->pluck('value', 'key')->all())->toEqual(['controle_delai_min_points' => '45', 'controle_rafale_minutes' => '3', 'controle_rafale_nombre' => '7']);
    expect(json_decode((string) syncAudit()->where('action', 'poste.reglage_modifie')->orderByDesc('id')->value('details'), true))->toMatchArray(['controle_delai_min_points' => 45, 'controle_rafale_nombre' => 7, 'controle_rafale_minutes' => 3]);
    foreach (['pointsDelay' => 0, 'burstCount' => 1, 'burstMinutes' => 500] as $field => $bad) {
        $this->put('/administration/postes/reglages', ['inactivity' => 10, 'skew' => 600, 'pin' => 12, $field => $bad])->assertSessionHasErrors($field);
    }
    $this->post('/administration/postes', ['event_id' => (string) Str::uuid(), 'name' => 'x'])->assertSessionHasErrors('event_id');
    foreach (['poste.cree', 'poste.code_emis', 'poste.revoque', 'poste.reglage_modifie'] as $action) {
        expect(syncAudit()->where('action', $action)->exists())->toBeTrue($action);
    }
    // les conflits ouverts sont consultables (sans nom de personne)
    DB::table('sync_conflicts')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'device_id' => $this->deviceA, 'kind' => 'conflit', 'reason' => 'deja_associe', 'operation_type' => 'badge', 'created_at' => now(), 'updated_at' => now()]);
    $this->get('/administration/postes')->assertInertia(fn (AssertableInertia $p) => $p->has('conflicts', 1)->where('conflicts.0.reason', 'deja_associe'));
});
