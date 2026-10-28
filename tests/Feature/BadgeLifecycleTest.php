<?php

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use App\Services\Audit;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\QrSvg;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function badgeAccount(string $role): User
{
    $user = User::create(['name' => 'Compte fictif L4', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** Visiteurs fictifs du seed : i % 3 = 0 VIP, 1 Visiteur, 2 Prestataire (validité 10 janvier 2027, 08:00 à 20:00 UTC). */
function demoVisitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

function demoLot(int $expected, int $category = 1): Badge
{
    $lots = app(BadgeLots::class);
    $batch = $lots->generate(DemoSeeder::id('event'), DemoSeeder::id('category'.$category), $expected, badgeAccount('administrateur'));

    return Badge::where('badge_batch_id', $batch->id)->orderBy('code')->firstOrFail();
}

/** Lignes de journal écrites par le test courant (les lignes d’autres tests, jamais supprimées, sont ignorées). */
function auditRows(): Builder
{
    return DB::table('audit_logs')->where('id', '>', test()->auditStart);
}

function refusalOf(callable $call): ?BadgeRefused
{
    try {
        $call();
    } catch (BadgeRefused $e) {
        return $e;
    }

    return null;
}

beforeEach(function () {
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->agent = badgeAccount('agent_accueil');
    $this->supervisor = badgeAccount('superviseur');
    $this->service = app(BadgeService::class);
});

it('génère un lot avec marge arrondie au supérieur, codes continus et jetons uniques', function () {
    $admin = badgeAccount('administrateur');
    $lots = app(BadgeLots::class);
    expect(BadgeLots::manufactured(20, 10))->toBe(22)->and(BadgeLots::manufactured(15, 10))->toBe(17)->and(BadgeLots::manufactured(1, 10))->toBe(2)->and(BadgeLots::manufactured(10, 0))->toBe(10);
    $event = DemoSeeder::id('event');
    $batch = $lots->generate($event, DemoSeeder::id('category1'), 15, $admin);
    $codes = DB::table('badges')->where('badge_batch_id', $batch->id)->orderBy('code')->pluck('code')->all();
    expect($codes)->toHaveCount(17)->and($codes[0])->toBe('B-401')->and($codes[16])->toBe('B-417');
    expect(DB::table('badges')->where('badge_batch_id', $batch->id)->where('category_id', DemoSeeder::id('category1'))->count())->toBe(17);
    $next = $lots->generate($event, DemoSeeder::id('category3'), 3, $admin);
    expect(DB::table('badges')->where('badge_batch_id', $next->id)->orderBy('code')->pluck('code')->all())->toBe(['B-418', 'B-419', 'B-420', 'B-421'])
        ->and(DB::table('badge_batches')->where('id', $batch->id)->first())->toMatchObject(['quantity' => 17, 'margin' => 2]);
    $tokens = DB::table('badges')->whereIn('badge_batch_id', [$batch->id, $next->id])->pluck('qr_token');
    expect($tokens->unique())->toHaveCount(21);
    foreach ($tokens as $token) {
        expect($token)->toMatch('/^[0-9a-f]{64}$/');
    }
    expect(auditRows()->where('action', 'badge.lot_cree')->count())->toBe(2);
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '25']);
    expect(DB::table('badges')->where('badge_batch_id', $lots->generate($event, DemoSeeder::id('category0'), 10, $admin)->id)->count())->toBe(13);
});

it('refuse un lot incohérent', function () {
    $admin = badgeAccount('administrateur');
    $lots = app(BadgeLots::class);
    expect(refusalOf(fn () => $lots->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 0, $admin))?->reason)->toBe('quantite');
    expect(refusalOf(fn () => $lots->generate(DemoSeeder::id('event'), (string) Str::uuid(), 5, $admin))?->reason)->toBe('categorie');
});

it('génère 400 badges en moins de 5 secondes', function () {
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $start = microtime(true);
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 400, badgeAccount('administrateur'));
    expect(microtime(true) - $start)->toBeLessThan(5.0)->and(DB::table('badges')->where('badge_batch_id', $batch->id)->count())->toBe(400);
});

it('réserve une plage à un poste ou à un groupe, tout ou rien, et journalise', function () {
    $admin = badgeAccount('administrateur');
    $first = demoLot(20);
    $batch = $first->badge_batch_id;
    $lots = app(BadgeLots::class);
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $base = (int) substr($first->code, 2);
    expect($lots->reserve($batch, $base, $base + 4, $device, $admin))->toBe(5);
    expect(DB::table('badges')->where('badge_batch_id', $batch)->where('reserved_device_id', $device->id)->count())->toBe(5);
    expect(refusalOf(fn () => $lots->reserve($batch, $base + 3, $base + 7, $device, $admin))?->reason)->toBe('indisponible');
    $group = Group::findOrFail(DemoSeeder::id('group'));
    $start = $base + 3;
    expect(refusalOf(fn () => $lots->reserve($batch, $start, $start + 3, $group, $admin))?->reason)->toBe('indisponible');
    expect(DB::table('badges')->where('badge_batch_id', $batch)->whereNotNull('reserved_group_id')->count())->toBe(0);
    $from = $base + 10;
    expect($lots->reserve($batch, $from, $from + 2, $group, $admin))->toBe(3);
    expect(DB::table('badges')->where('badge_batch_id', $batch)->where('reserved_group_id', $group->id)->whereNull('reserved_device_id')->count())->toBe(3);
    expect(refusalOf(fn () => $lots->reserve($batch, $base + 900, $base + 905, $group, $admin))?->reason)->toBe('plage');
    expect(refusalOf(fn () => $lots->reserve($batch, 5, 2, $group, $admin))?->reason)->toBe('plage');
    $details = auditRows()->where('action', 'badge.reserve')->orderBy('id')->pluck('details')->map(fn ($d) => json_decode($d, true));
    expect($details)->toHaveCount(2)->and($details[0])->toMatchArray(['nombre' => 5, 'cible' => 'poste', 'cible_id' => $device->id])->and($details[1])->toMatchArray(['nombre' => 3, 'cible' => 'groupe']);
});

it('associe un badge disponible de la bonne catégorie, de façon atomique et journalisée', function () {
    $badge = demoLot(5);
    $assignment = $this->service->associer($badge, demoVisitor(1), $this->agent);
    expect($badge->fresh()->status)->toBe(BadgeStatus::Assigned)->and($assignment->returned_at)->toBeNull()->and($assignment->actor_id)->toBe($this->agent->id);
    $log = auditRows()->where('action', 'badge.associe')->first();
    expect(json_decode($log->details, true))->toMatchArray(['badge_id' => $badge->id, 'visitor_id' => demoVisitor(1), 'assignment_id' => $assignment->id])->and($log->actor_id)->toBe($this->agent->id);
});

it('refuse un visiteur déjà badgé, une catégorie incompatible et un badge hérité sans catégorie', function () {
    $lot = demoLot(8);
    $other = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->skip(1)->firstOrFail();
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    $refusal = refusalOf(fn () => $this->service->associer($other, demoVisitor(1), $this->agent));
    expect($refusal->reason)->toBe('visiteur_deja_badge')->and($refusal->getMessage())->toContain($lot->code);
    expect($other->fresh()->status)->toBe(BadgeStatus::Available);

    $refusal = refusalOf(fn () => $this->service->associer($other, demoVisitor(0), $this->agent));
    expect($refusal->reason)->toBe('categorie')->and($refusal->getMessage())->toBe('Badge de catégorie Visiteur, visiteur de catégorie VIP');

    $legacy = Badge::where('code', 'B-399')->firstOrFail();
    expect(refusalOf(fn () => $this->service->associer($legacy, demoVisitor(1), $this->agent))?->reason)->toBe('sans_categorie');
});

it('refuse un badge déjà associé en indiquant la personne, et un badge annulé', function () {
    $lot = demoLot(5);
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    $refusal = refusalOf(fn () => $this->service->associer($lot, demoVisitor(4), $this->agent));
    $name = DB::table('visitors')->where('id', demoVisitor(1))->value('full_name');
    expect($refusal->reason)->toBe('deja_associe')->and($refusal->getMessage())->toContain($name);
    $this->service->annuler($lot, $this->supervisor, 'Perdu');
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(4), $this->agent))?->reason)->toBe('annule');
});

it('réserve un badge à un poste : association refusée ailleurs, acceptée depuis ce poste', function () {
    $admin = badgeAccount('administrateur');
    $lot = demoLot(6);
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $number = (int) substr($lot->code, 2);
    app(BadgeLots::class)->reserve($lot->badge_batch_id, $number, $number, $device, $admin);
    $otherId = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $otherId, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette fictive B', 'station' => 'Poste fictif B', 'created_at' => now(), 'updated_at' => now()]);
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->agent, $otherId))?->reason)->toBe('autre_poste');
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->agent))?->reason)->toBe('autre_poste');
    expect($lot->fresh()->status)->toBe(BadgeStatus::Available);
    $assignment = $this->service->associer($lot, demoVisitor(1), $this->agent, $device);
    expect($assignment->device_id)->toBe($device->id);
});

it('contrôle la période de validité du visiteur', function () {
    $lot = demoLot(6);
    $other = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->skip(1)->firstOrFail();
    $this->travelTo('2027-01-10 07:59:00');
    $refusal = refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->agent));
    expect($refusal->reason)->toBe('hors_periode')->and($refusal->getMessage())->toContain("hors période : avis de l'hôte ou du superviseur requis");
    $this->travelTo('2027-01-10 20:01:00');
    expect(refusalOf(fn () => $this->service->associer($other, demoVisitor(1), $this->agent))?->reason)->toBe('hors_periode');
    $this->travelTo('2027-01-10 20:00:00');
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    expect($lot->fresh()->status)->toBe(BadgeStatus::Assigned);
});

it('refuse l’association à un visiteur dont l’accès a été refusé', function () {
    $lot = demoLot(3);
    DB::table('visitors')->where('id', demoVisitor(1))->update(['status' => 'refuse']);
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->agent))?->reason)->toBe('visiteur_refuse');
});

it('applique les deux modes du réglage de réutilisation à la restitution', function () {
    $lot = demoLot(6);
    $other = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->skip(1)->firstOrFail();
    expect(DB::table('settings')->where('key', 'badge_reutilisable')->value('value'))->toBe('non');
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    $this->service->restituer($lot, $this->agent);
    expect($lot->fresh()->status)->toBe(BadgeStatus::Closed);
    expect(DB::table('badge_assignments')->where('badge_id', $lot->id)->whereNotNull('returned_at')->count())->toBe(1);
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(4), $this->agent))?->reason)->toBe('indisponible');
    expect(refusalOf(fn () => $this->service->restituer($lot, $this->agent))?->reason)->toBe('non_associe');

    DB::table('settings')->where('key', 'badge_reutilisable')->update(['value' => 'oui']);
    $this->service->associer($other, demoVisitor(1), $this->agent);
    $this->service->restituer($other, $this->agent);
    expect($other->fresh()->status)->toBe(BadgeStatus::Available);
    $this->service->associer($other, demoVisitor(4), $this->agent);
    expect($other->fresh()->status)->toBe(BadgeStatus::Assigned);
    $final = auditRows()->where('action', 'badge.restitue')->orderBy('id')->pluck('details')->map(fn ($d) => json_decode($d, true)['statut_final']);
    expect($final->all())->toBe(['Clos', 'Disponible']);
});

it('annule définitivement avec un motif obligatoire, réservé au superviseur', function () {
    $lot = demoLot(4);
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    expect(refusalOf(fn () => $this->service->annuler($lot, $this->supervisor, '  '))?->reason)->toBe('motif_obligatoire');
    expect(refusalOf(fn () => $this->service->annuler($lot, $this->agent, 'Perdu'))?->reason)->toBe('non_autorise');
    expect($lot->fresh()->status)->toBe(BadgeStatus::Assigned);
    $this->service->annuler($lot, $this->supervisor, 'Badge perdu');
    $assignment = DB::table('badge_assignments')->where('badge_id', $lot->id)->first();
    expect($lot->fresh()->status)->toBe(BadgeStatus::Cancelled)->and($assignment->cancelled_at)->not->toBeNull()->and($assignment->cancellation_reason)->toBe('Badge perdu');
    expect($lot->fresh()->cancellation_reason)->toBe('Badge perdu');
    expect(refusalOf(fn () => $this->service->annuler($lot, $this->supervisor, 'Encore'))?->reason)->toBe('non_annulable');
    expect(refusalOf(fn () => $this->service->restituer($lot, $this->agent))?->reason)->toBe('non_associe');
    // la personne peut recevoir un autre badge
    $other = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->skip(1)->firstOrFail();
    $this->service->associer($other, demoVisitor(1), $this->agent);
    expect($other->fresh()->status)->toBe(BadgeStatus::Assigned);
});

it('annule un badge disponible abîmé avant toute association, sans historique d’association', function () {
    $lot = demoLot(4);
    $this->service->annuler($lot, $this->supervisor, 'Badge abîmé');
    expect($lot->fresh()->status)->toBe(BadgeStatus::Cancelled)->and($lot->fresh()->cancellation_reason)->toBe('Badge abîmé');
    expect(DB::table('badge_assignments')->where('badge_id', $lot->id)->count())->toBe(0);
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->agent))?->reason)->toBe('annule');
    expect($this->service->controler($lot->code, $lot->event_id)->reason)->toBe('annule');
    $log = json_decode(auditRows()->where('action', 'badge.annule')->value('details'), true);
    expect($log['badge_id'])->toBe($lot->id)->and($log['visitor_id'])->toBeNull()->and($log['assignment_id'])->toBeNull();
    expect(refusalOf(fn () => $this->service->annuler($lot, $this->agent, 'x'))?->reason)->toBe('non_autorise');
});

it('refuse toute opération sans la permission correspondante', function () {
    $lot = demoLot(3);
    $admin = badgeAccount('administrateur');
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $this->supervisor))?->reason)->toBe('non_autorise');
    expect(refusalOf(fn () => $this->service->associer($lot, demoVisitor(1), $admin))?->reason)->toBe('non_autorise');
    expect(refusalOf(fn () => $this->service->restituer($lot, $admin))?->reason)->toBe('non_autorise');
    expect(auditRows()->where('action', 'like', 'badge.%')->where('action', '<>', 'badge.lot_cree')->count())->toBe(0);
});

it('contrôle un badge sans rien modifier et donne la raison précise du refus', function () {
    $lot = demoLot(8);
    $badges = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->get();
    $token = DB::table('badges')->where('id', $badges[0]->id)->value('qr_token');
    $event = DemoSeeder::id('event');

    $verdict = $this->service->controler($badges[0]->code, $event);
    expect($verdict->valid)->toBeFalse()->and($verdict->reason)->toBe('non_associe');

    $this->service->associer($badges[0], demoVisitor(1), $this->agent);
    $before = [DB::table('badges')->orderBy('id')->get()->toJson(), DB::table('badge_assignments')->orderBy('id')->get()->toJson()];
    $verdict = $this->service->controler(QrSvg::payload($token));
    $visitor = DB::table('visitors')->where('id', demoVisitor(1))->first();
    expect($verdict->valid)->toBeTrue()->and($verdict->reason)->toBeNull()->and($verdict->person)->toBe(['id' => $visitor->id, 'name' => $visitor->full_name])
        ->and($verdict->organisation)->toBe($visitor->organisation)->and($verdict->category['name'])->toBe('Visiteur')->and($verdict->zones)->toBe(['Accueil', 'Cour', 'Salle principale']);
    expect($this->service->controler(strtolower($badges[0]->code), $event)->valid)->toBeTrue();
    expect($this->service->controler($token)->valid)->toBeTrue();
    expect([DB::table('badges')->orderBy('id')->get()->toJson(), DB::table('badge_assignments')->orderBy('id')->get()->toJson()])->toBe($before);
    expect(auditRows()->where('action', 'badge.controle_refuse')->count())->toBe(1);

    $this->travelTo('2027-01-11 09:00:00');
    expect($this->service->controler($badges[0]->code, $event)->reason)->toBe('hors_periode');
    $this->travelTo('2027-01-10 10:00:00');

    $this->service->associer($badges[1], demoVisitor(4), $this->agent);
    $this->service->restituer($badges[1], $this->agent);
    expect($this->service->controler($badges[1]->code, $event)->reason)->toBe('restitue');

    $this->service->associer($badges[2], demoVisitor(7), $this->agent);
    $this->service->annuler($badges[2], $this->supervisor, 'Volé');
    $cancelled = $this->service->controler($badges[2]->code, $event);
    expect($cancelled->valid)->toBeFalse()->and($cancelled->reason)->toBe('annule')->and($cancelled->person)->toBeNull();
    expect($this->service->controler('B-9999', $event)->reason)->toBe('inconnu');
    expect($this->service->controler(str_repeat('a', 64))->reason)->toBe('inconnu');
    expect($this->service->controler('  ')->reason)->toBe('inconnu');
    expect(auditRows()->where('action', 'badge.controle_refuse')->count())->toBe(7);
});

it('exige l’événement quand le même code existe dans deux événements', function () {
    $second = (string) Str::uuid();
    $category = (string) Str::uuid();
    DB::table('events')->insert(['id' => $second, 'name' => 'Second événement fictif', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $second, 'name' => 'Visiteur', 'color' => '#0F5AA6', 'created_at' => now(), 'updated_at' => now()]);
    $batch = app(BadgeLots::class)->generate($second, $category, 2, badgeAccount('administrateur'));
    expect(DB::table('badges')->where('badge_batch_id', $batch->id)->orderBy('code')->pluck('code')->all())->toBe(['B-001', 'B-002', 'B-003']);
    expect($this->service->controler('B-001')->reason)->toBe('evenement_requis');
    expect($this->service->controler('B-001', $second)->reason)->toBe('non_associe');
});

it('refuse en base les transitions interdites de statut et laisse les définitifs intacts', function () {
    $allowed = [['Disponible', 'Associé'], ['Disponible', 'Annulé'], ['Associé', 'Restitué'], ['Associé', 'Annulé'], ['Restitué', 'Disponible'], ['Restitué', 'Clos']];
    $all = array_column(BadgeStatus::cases(), 'value');
    $lot = demoLot(2);
    $make = function (string $status) use ($lot) {
        $id = (string) Str::uuid();
        DB::table('badges')->insert(['id' => $id, 'event_id' => $lot->event_id, 'code' => 'T-'.Str::upper(Str::random(8)), 'qr_token' => bin2hex(random_bytes(32)), 'status' => $status, 'badge_batch_id' => $lot->badge_batch_id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    foreach ($all as $from) {
        foreach ($all as $to) {
            if ($from === $to) {
                continue;
            }
            $id = $make($from);
            try {
                DB::transaction(fn () => DB::table('badges')->where('id', $id)->update(['status' => $to]));
                $ok = true;
            } catch (QueryException $e) {
                expect($e->getCode())->toBe('23514');
                $ok = false;
            }
            expect($ok)->toBe(in_array([$from, $to], $allowed, true), "$from → $to");
            expect(DB::table('badges')->where('id', $id)->value('status'))->toBe($ok ? $to : $from);
        }
    }
    $id = $make('Disponible');
    DB::table('badges')->where('id', $id)->update(['reserved_device_id' => DemoSeeder::id('device'), 'status' => 'Disponible']);
    expect(DB::table('badges')->where('id', $id)->value('reserved_device_id'))->toBe(DemoSeeder::id('device'));
});

it('empêche le compte applicatif de contourner le déclencheur', function () {
    foreach (['ALTER TABLE badges DISABLE TRIGGER badges_status_guard', 'DROP TRIGGER badges_status_guard ON badges', 'TRUNCATE badges'] as $sql) {
        try {
            DB::transaction(fn () => DB::statement($sql));
            $this->fail("Instruction acceptée : $sql");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('42501');
        }
    }
});

it('garantit en base un seul badge actif par visiteur', function () {
    $lot = demoLot(4);
    $badges = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->get();
    $row = fn (Badge $b) => ['id' => (string) Str::uuid(), 'event_id' => $b->event_id, 'badge_id' => $b->id, 'visitor_id' => demoVisitor(1), 'assigned_at' => now()];
    DB::table('badge_assignments')->insert($row($badges[0]));
    try {
        DB::transaction(fn () => DB::table('badge_assignments')->insert($row($badges[1])));
        $this->fail('Second badge actif accepté');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505')->and($e->getMessage())->toContain('badge_assignments_one_active_visitor');
    }
    DB::table('badge_assignments')->where('badge_id', $badges[0]->id)->update(['returned_at' => now()]);
    DB::table('badge_assignments')->insert($row($badges[1]));
    expect(DB::table('badge_assignments')->where('visitor_id', demoVisitor(1))->whereNull('returned_at')->count())->toBe(1);
});

it('garde access_events et identity_checks en ajout seul, la correction étant un nouvel événement', function () {
    foreach (['access_events', 'identity_checks'] as $table) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $right) {
            expect(DB::selectOne("SELECT has_table_privilege(current_user, '$table', '$right') AS ok")->ok)->toBeFalse("$table $right");
        }
        expect(DB::selectOne("SELECT has_table_privilege(current_user, '$table', 'INSERT') AS ok")->ok)->toBeTrue();
    }
    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $right) {
        expect(DB::selectOne("SELECT has_table_privilege(current_user, 'audit_logs', '$right') AS ok")->ok)->toBeFalse();
    }
    $event = DemoSeeder::id('event');
    $first = (string) Str::uuid();
    $base = ['event_id' => $event, 'visitor_id' => demoVisitor(1), 'type' => 'entree', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    DB::table('access_events')->insert([...$base, 'id' => $first]);
    DB::table('access_events')->insert([...$base, 'id' => (string) Str::uuid(), 'type' => 'correction', 'corrige_id' => $first]);
    $check = (string) Str::uuid();
    DB::table('identity_checks')->insert(['id' => $check, 'visitor_id' => demoVisitor(1), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['access_events', $first], ['identity_checks', $check]] as [$table, $id]) {
        foreach (['update' => fn () => DB::table($table)->where('id', $id)->update(['updated_at' => now()]), 'delete' => fn () => DB::table($table)->where('id', $id)->delete()] as $name => $call) {
            try {
                DB::transaction($call);
                $this->fail("$name accepté sur $table");
            } catch (QueryException $e) {
                expect($e->getCode())->toBe('42501');
            }
        }
    }
    try {
        DB::transaction(fn () => DB::table('access_events')->insert([...$base, 'id' => (string) Str::uuid(), 'corrige_id' => (string) Str::uuid()]));
        $this->fail('Correction d’un événement inexistant acceptée');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23503');
    }
});

it('n’écrit aucune donnée personnelle ni aucun jeton dans le journal', function () {
    $lot = demoLot(8);
    $badges = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->get();
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $number = (int) substr($badges[0]->code, 2);
    app(BadgeLots::class)->reserve($lot->badge_batch_id, $number, $number, $device, badgeAccount('administrateur'));
    $number = (int) substr($badges[3]->code, 2);
    app(BadgeLots::class)->reserve($lot->badge_batch_id, $number, $number, $device, badgeAccount('administrateur'));
    $this->service->associer($badges[0], demoVisitor(1), $this->agent, $device);
    $this->service->controler($badges[1]->code, $lot->event_id);
    $this->service->controler($badges[0]->code.'-inconnu');
    $this->service->restituer($badges[0], $this->agent);
    $this->service->associer($badges[2], demoVisitor(4), $this->agent);
    $this->service->annuler($badges[2], $this->supervisor, 'Motif confidentiel de test');
    $logs = auditRows()->where('action', 'like', 'badge.%')->get();
    expect($logs->pluck('action')->unique()->sort()->values()->all())->toBe(['badge.annule', 'badge.associe', 'badge.controle_refuse', 'badge.lot_cree', 'badge.reserve', 'badge.restitue']);
    $names = DB::table('visitors')->pluck('full_name')->unique()->all();
    $tokens = DB::table('badges')->whereIn('badge_batch_id', [$lot->badge_batch_id])->pluck('qr_token')->all();
    foreach ($logs as $log) {
        foreach ($names as $name) {
            expect($log->details)->not->toContain($name);
        }
        foreach ($tokens as $token) {
            expect($log->details)->not->toContain($token);
        }
        expect($log->details)->not->toContain('confidentiel')->and($log->details)->not->toContain('Compte fictif');
        foreach (json_decode($log->details, true) as $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue();
        }
    }
    expect(app(Audit::class))->toBeInstanceOf(Audit::class);
});

it('dessine des QR conformes à l’encodeur, dont le contenu est IKOMA1: suivi du jeton, sans donnée personnelle', function () {
    $lot = demoLot(6);
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    $badges = DB::table('badges')->where('badge_batch_id', $lot->badge_batch_id)->get();
    expect($badges)->toHaveCount(7);
    foreach ($badges as $badge) {
        $svg = app(QrSvg::class)->render(QrSvg::payload($badge->qr_token), 'QR du badge '.$badge->code);
        expect($svg)->not->toContain($badge->qr_token)->and($svg)->not->toContain('http');
        expect(QrSvg::payload($badge->qr_token))->toBe('IKOMA1:'.$badge->qr_token)->and(strlen(QrSvg::payload($badge->qr_token)))->toBe(71);
        expect(svgModules($svg))->toBe(encoderModules(QrSvg::payload($badge->qr_token)));
        expect($this->service->controler(QrSvg::payload($badge->qr_token))->reason)->toBe($badge->id === $lot->id ? null : 'non_associe');
    }
});

/** Modules sombres lus dans le SVG de QrSvg (rectangles « M x y h w v1 h-w z », zone de silence de 4 modules retirée). */
function svgModules(string $svg): array
{
    preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', $svg, $runs, PREG_SET_ORDER);
    $modules = [];
    foreach ($runs as [, $x, $y, $w]) {
        for ($i = 0; $i < (int) $w; $i++) {
            $modules[] = ((int) $x - 4 + $i).','.((int) $y - 4);
        }
    }
    sort($modules);

    return $modules;
}

/** Modules sombres calculés directement par l’encodeur de bacon/bacon-qr-code. */
function encoderModules(string $payload): array
{
    $matrix = Encoder::encode($payload, ErrorCorrectionLevel::Q(), 'ISO-8859-1', null, false)->getMatrix();
    $modules = [];
    for ($y = 0; $y < $matrix->getHeight(); $y++) {
        for ($x = 0; $x < $matrix->getWidth(); $x++) {
            if ($matrix->get($x, $y) === 1) {
                $modules[] = "$x,$y";
            }
        }
    }
    sort($modules);

    return $modules;
}

it('réserve la création de lots, réservations, réglages, étiquettes et démonstration à badges.gerer', function () {
    $admin = badgeAccount('administrateur');
    $event = DemoSeeder::id('event');
    foreach (['superviseur', 'agent_accueil'] as $role) {
        $this->actingAs(badgeAccount($role));
        $this->post('/administration/badges/lots', ['event_id' => $event, 'category_id' => DemoSeeder::id('category1'), 'expected' => 5])->assertForbidden();
        $this->post('/administration/badges/reservations', [])->assertForbidden();
        $this->put('/administration/badges/reglages', ['margin' => 5, 'reusable' => 'oui'])->assertForbidden();
        $this->get('/administration/badges/etiquettes?batch='.DemoSeeder::id('batch'))->assertForbidden();
    }
    expect(DB::table('settings')->where('key', 'badge_marge_pourcent')->value('value'))->toBe('10');
    $this->actingAs($admin)->post('/administration/badges', [])->assertStatus(405);
    $this->post('/administration/badges/lots', ['event_id' => $event, 'category_id' => DemoSeeder::id('category1'), 'expected' => 10])->assertRedirect();
    expect(DB::table('badge_batches')->where('event_id', $event)->where('category_id', DemoSeeder::id('category1'))->value('quantity'))->toBe(11);
    $this->post('/administration/badges/lots', ['event_id' => $event, 'category_id' => DemoSeeder::id('category1'), 'expected' => 0])->assertSessionHasErrors('expected');
    $this->post('/administration/badges/lots', ['event_id' => $event, 'category_id' => (string) Str::uuid(), 'expected' => 3])->assertSessionHasErrors('category_id');
    $this->put('/administration/badges/reglages', ['margin' => 150, 'reusable' => 'oui'])->assertSessionHasErrors('margin');
    $this->put('/administration/badges/reglages', ['margin' => 20, 'reusable' => 'peut-être'])->assertSessionHasErrors('reusable');
    $this->put('/administration/badges/reglages', ['margin' => 20, 'reusable' => 'oui'])->assertRedirect();
    expect(DB::table('settings')->whereIn('key', ['badge_marge_pourcent', 'badge_reutilisable'])->orderBy('key')->pluck('value', 'key')->all())->toBe(['badge_marge_pourcent' => '20', 'badge_reutilisable' => 'oui']);
});

it('réserve une plage depuis l’écran et refuse proprement les plages impossibles', function () {
    $admin = badgeAccount('administrateur');
    $lot = demoLot(10);
    $this->actingAs($admin);
    $payload = ['batch_id' => $lot->badge_batch_id, 'from' => 401, 'to' => 405, 'target_type' => 'poste', 'target_id' => DemoSeeder::id('device')];
    $first = (int) substr($lot->code, 2);
    $this->post('/administration/badges/reservations', [...$payload, 'from' => $first, 'to' => $first + 4])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('badges')->where('badge_batch_id', $lot->badge_batch_id)->where('reserved_device_id', DemoSeeder::id('device'))->count())->toBe(5);
    $this->post('/administration/badges/reservations', [...$payload, 'from' => $first, 'to' => $first + 1])->assertSessionHasErrors('badge');
    $this->post('/administration/badges/reservations', [...$payload, 'to' => 3])->assertSessionHasErrors('to');
    $this->post('/administration/badges/reservations', [...$payload, 'target_type' => 'groupe'])->assertSessionHasErrors('target_id');
    $this->post('/administration/badges/reservations', [...$payload, 'from' => $first + 5, 'to' => $first + 6, 'target_type' => 'groupe', 'target_id' => DemoSeeder::id('group')])->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/administration/badges?batch='.$lot->badge_batch_id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Badges')->where('badges.total', 11)->where('badges.data.0.category_name', 'Visiteur')->where('badges.data.0.reserved_station', 'Poste fictif A')->where('badges.data.5.reserved_group', 'Groupe fictif de stagiaires')->missing('badges.data.0.qr_token')->where('settings.margin', 10)->where('settings.reusable', false));
});

it('affiche l’écran des badges sans aucun nom de personne', function () {
    $lot = demoLot(3);
    $this->service->associer($lot, demoVisitor(1), $this->agent);
    $name = DB::table('visitors')->where('id', demoVisitor(1))->value('full_name');
    $response = $this->actingAs(badgeAccount('administrateur'))->get('/administration/badges?status=Associé')->assertOk();
    expect($response->getContent())->not->toContain($name);
    $response->assertInertia(fn (Assert $p) => $p->where('badges.total', 1)->has('batches.0.first_number')->has('events')->has('devices')->has('groups')->has('categories'));
});

it('imprime 400 étiquettes A4 sans aucune ressource externe ni jeton en clair', function () {
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $lot = demoLot(400);
    $this->actingAs(badgeAccount('administrateur'));
    $start = microtime(true);
    $response = $this->get('/administration/badges/etiquettes?batch='.$lot->badge_batch_id.'&cols=4&rows=10')->assertOk();
    $html = $response->getContent();
    expect(microtime(true) - $start)->toBeLessThan(30.0);
    // Politique de sécurité (L11) : le bloc de style de la page d'impression n'est permis que par son nonce, propre à la réponse.
    preg_match('/<style nonce="([^"]+)"/', $html, $found);
    expect($found)->toHaveCount(2)->and($response->headers->get('Content-Security-Policy'))->toContain("style-src 'self' 'nonce-{$found[1]}'");
    expect(substr_count($html, '<article class="label"'))->toBe(400)->and(substr_count($html, '<section class="sheet"'))->toBe(10);
    expect($html)->not->toMatch('/(src|href|action)="https?:/i')->and($html)->not->toMatch('/url\(\s*[\'"]?https?:/i')->and($html)->not->toContain('<link')->and($html)->not->toContain('onclick')->and($html)->not->toContain('@import');
    // Un seul script, en ligne, autorisé par le même nonce que le style : le bouton « Imprimer » (aucun gestionnaire en ligne).
    expect(substr_count($html, '<script'))->toBe(1)->and($html)->toContain('<script nonce="'.$found[1].'"')->and($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self' 'nonce-{$found[1]}'");
    expect($html)->toContain('@media print')->and($html)->toContain('size: A4')->and($html)->toContain('Visiteur')->and($html)->toContain('B-401')->and($html)->toContain('B-800');
    $tokens = DB::table('badges')->where('badge_batch_id', $lot->badge_batch_id)->pluck('qr_token');
    foreach ($tokens->take(25) as $token) {
        expect($html)->not->toContain($token);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    preg_match_all('/<svg.*?<\/svg>/s', $html, $svgs);
    expect($svgs[0])->toHaveCount(400);
    $expected = DB::table('badges')->where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->skip(17)->value('qr_token');
    expect(svgModules($svgs[0][17]))->toBe(encoderModules(QrSvg::payload($expected)));
});

it('imprime une plage avec une grille réglable, en indiquant catégorie, symbole et numéro', function () {
    $lot = demoLot(10, 3);
    $first = (int) substr($lot->code, 2);
    $this->actingAs(badgeAccount('administrateur'));
    $html = $this->get('/administration/badges/etiquettes?batch='.$lot->badge_batch_id.'&from='.($first + 2).'&to='.($first + 4).'&cols=2&rows=3')->assertOk()->getContent();
    expect(substr_count($html, '<article class="label"'))->toBe(3)->and($html)->toContain('repeat(2, 1fr)')->and($html)->toContain('repeat(3, 1fr)')->and($html)->toContain('Stagiaire')->and($html)->toContain('■')->and($html)->toContain('N° '.($first + 2))->and($html)->not->toContain('B-'.$first.'<');
    $this->get('/administration/badges/etiquettes?batch='.$lot->badge_batch_id.'&cols=9')->assertSessionHasErrors('cols');
    $this->get('/administration/badges/etiquettes?batch='.(string) Str::uuid())->assertSessionHasErrors('batch');
});

it('limite le nombre d’étiquettes par page et affiche un message clair', function () {
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $lot = demoLot(1001);
    $html = $this->actingAs(badgeAccount('administrateur'))->get('/administration/badges/etiquettes?batch='.$lot->badge_batch_id)->assertOk()->getContent();
    expect($html)->toContain('Choisissez une plage')->and(substr_count($html, '<article'))->toBe(0);
});

it('ne propose plus de route de contrôle de démonstration (retirée au lot L5)', function () {
    $this->actingAs(badgeAccount('administrateur'));
    $this->postJson('/administration/badges/controle-demo', ['code' => 'B-001'])->assertNotFound();
});
