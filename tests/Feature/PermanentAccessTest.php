<?php

use App\Models\User;
use App\Services\BadgeLots;
use App\Services\CardLifecycle;
use App\Services\DataLifecycle;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use App\Services\Presence;
use App\Services\QrSvg;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

const PX_TOKEN_A = '5555555555555555555555555555555555555555555555555555555555555555';
const PX_TOKEN_B = '6666666666666666666666666666666666666666666666666666666666666666';

function pxUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif A06', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function pxHeaders(string $token = PX_TOKEN_A): array
{
    return ['X-Device-Token' => $token, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

/** Titulaire fictif avec sa carte émise ; retourne [holderId, jeton QR]. */
function pxCard(string $category = 'AILCT-01'): array
{
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => $category], test()->admin);
    app(HolderCards::class)->issue($holder, test()->admin);

    return [$holder, pxToken($holder)];
}

function pxToken(string $holder): string
{
    return (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->where('k.status', 'active')->value('b.qr_token');
}

/** Événement fictif avec une zone « Zone P » (politique acceptant les permanents) et un poste enrôlé ; retourne [eventId, zoneId, deviceId]. */
function pxEvent(string $token, bool $policy = true, bool $offline = false): array
{
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif A06 '.Str::random(4), 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'created_at' => now(), 'updated_at' => now()]);
    $zone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $zone, 'event_id' => $event, 'name' => 'Zone P', 'est_accueil' => false, 'created_at' => now(), 'updated_at' => now()]);
    if ($policy) {
        DB::table('zone_policies')->insert(['zone_id' => $zone, 'event_id' => $event, 'classification' => 'NEUTRE-TEST', 'permanent_allowed' => true, 'offline_entry_allowed' => $offline, 'created_at' => now(), 'updated_at' => now()]);
    }
    $device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $device, 'event_id' => $event, 'name' => 'Poste fictif', 'station' => 'Entrée', 'token_hash' => hash('sha256', $token), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    return [$event, $zone, $device];
}

function pxGrant(string $holder, string $event, string $zone, string $from = '-1 hour', string $until = '+8 hours'): string
{
    return app(HolderEntitlements::class)->grant($holder, $event, $zone, now()->modify($from)->toIso8601String(), now()->modify($until)->toIso8601String(), test()->admin);
}

function pxControl(string $token, ?array $point = null, string $deviceToken = PX_TOKEN_A)
{
    return test()->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$token, 'point' => $point], pxHeaders($deviceToken));
}

function pxExit(string $token, string $deviceToken = PX_TOKEN_A)
{
    return test()->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.$token], pxHeaders($deviceToken));
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = pxUser('administrateur');
    $this->agent = pxUser('agent_accueil');
    app(InstitutionalCategories::class)->initialize($this->admin);
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    [$this->event, $this->zone, $this->device] = pxEvent(PX_TOKEN_A);
    $this->actingAs($this->agent);
});

// --- A06.1 cycle de vie -----------------------------------------------------------------------------------------------------

it('déclare une carte perdue, la révoque et la remplace sans toucher à l’identité du titulaire', function () {
    [$holder, $token] = pxCard('AILCT-04');
    $before = DB::table('permanent_holders')->where('id', $holder)->first();
    $first = DB::table('holder_cards')->where('holder_id', $holder)->first();
    $lifecycle = app(CardLifecycle::class);
    $lifecycle->revoke($holder, 'perdue', 'Perdue dans les locaux (fictif)', $this->admin);
    $lost = DB::table('holder_cards')->where('id', $first->id)->first();
    expect($lost->status)->toBe('perdue')->and($lost->revoked_at)->not->toBeNull()->and(DB::table('badges')->where('id', $lost->badge_id)->value('status'))->toBe('Annulé');
    $new = $lifecycle->replace($holder, 'perdue', null, $this->admin);
    $newCard = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.id', $new)->first(['k.status', 'k.rank', 'b.code', 'b.qr_token']);
    $oldBadge = DB::table('badges')->where('id', $first->badge_id)->first(['code', 'qr_token']);
    expect($newCard->status)->toBe('active')->and($newCard->rank)->toBe(2)->and($newCard->code)->not->toBe($oldBadge->code)->and($newCard->qr_token)->not->toBe($oldBadge->qr_token)
        ->and(DB::table('holder_cards')->where('id', $first->id)->value('replaced_by_card_id'))->toBe($new);
    $after = DB::table('permanent_holders')->where('id', $holder)->first();
    expect($after->identifier)->toBe($before->identifier)->and($after->id)->toBe($before->id)->and($after->full_name)->toBe($before->full_name);
    expect(array_column($lifecycle->history($holder), 'rank'))->toBe([2, 1])->and(DB::table('audit_logs')->whereIn('action', ['carte.revoquee', 'carte.remplacee'])->count())->toBe(2);
});

it('remplace une carte encore active en la révoquant (vol) et garde la trace des tirages', function () {
    [$holder] = pxCard();
    app(HolderCards::class)->recordPrint($holder, null, $this->admin);
    $old = DB::table('holder_cards')->where('holder_id', $holder)->value('id');
    app(CardLifecycle::class)->replace($holder, 'volee', 'Vol déclaré (fictif)', $this->admin);
    expect(DB::table('holder_cards')->where('id', $old)->value('status'))->toBe('volee')->and(DB::table('holder_card_prints')->where('card_id', $old)->count())->toBe(1)
        ->and(DB::table('holder_cards')->where('holder_id', $holder)->where('status', 'active')->count())->toBe(1);
    $audit = DB::table('audit_logs')->whereIn('action', ['carte.revoquee', 'carte.remplacee'])->pluck('details')->implode(' ');
    expect($audit)->not->toContain('Vol déclaré')->and($audit)->not->toContain('Personne Fictive');
});

it('interdit toute réactivation d’une carte révoquée et tout recyclage de ses identifiants', function () {
    [$holder] = pxCard();
    app(CardLifecycle::class)->revoke($holder, 'compromise', null, $this->admin);
    $card = DB::table('holder_cards')->where('holder_id', $holder)->first();
    foreach (["UPDATE holder_cards SET status = 'active' WHERE id = '{$card->id}'", "UPDATE holder_cards SET revocation_reason = 'autre' WHERE id = '{$card->id}'", "UPDATE holder_cards SET revoked_at = NULL WHERE id = '{$card->id}'", "DELETE FROM holder_cards WHERE id = '{$card->id}'"] as $sql) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class);
    }
    expect(fn () => app(CardLifecycle::class)->revoke($holder, 'perdue', null, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => app(HolderCards::class)->recordPrint($holder, null, $this->admin))->toThrow(NotFoundHttpException::class);
    $badge = DB::table('badges')->where('id', $card->badge_id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('badges')->where('id', $badge->id)->update(['status' => 'Disponible'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('badges')->where('id', $badge->id)->delete()))->not->toThrow(Throwable::class);
    // Supprimé ou non, le code reste réservé : aucun nouveau badge ne le reprend.
    $next = app(BadgeLots::class)->issueSingle(DB::table('events')->where('is_permanent', true)->value('id'), DB::table('categories')->where('id', $badge->category_id)->value('id'), DB::table('visitors')->where('holder_id', $holder)->value('id'), 'Essai', $this->admin);
    expect($next['code'])->not->toBe($badge->code);
});

it('réserve le cycle de vie aux droits d’annulation de badge et valide les motifs', function () {
    [$holder] = pxCard();
    $lifecycle = app(CardLifecycle::class);
    expect(fn () => $lifecycle->revoke($holder, 'inconnu', null, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $lifecycle->revoke($holder, 'autre', null, $this->admin))->toThrow(ValidationException::class);
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(pxUser($role));
        $this->post('/administration/titulaires/'.$holder.'/carte/revoquer', ['reason' => 'perdue'])->assertForbidden();
        $this->post('/administration/titulaires/'.$holder.'/carte/remplacer', ['reason' => 'perdue'])->assertForbidden();
        $this->post('/administration/habilitations', ['holder_id' => $holder])->assertForbidden();
    }
    expect(DB::table('holder_cards')->where('holder_id', $holder)->value('status'))->toBe('active');
});

// --- A06.2 / A06.3 entrées, sorties, habilitations -------------------------------------------------------------------------

it('refuse immédiatement une carte perdue, volée ou révoquée, et prévient le superviseur', function () {
    foreach (['perdue', 'volee', 'compromise'] as $reason) {
        [$holder, $token] = pxCard();
        pxGrant($holder, $this->event, $this->zone);
        pxControl($token)->assertOk()->assertJson(['valid' => true]);
        app(CardLifecycle::class)->revoke($holder, $reason, null, $this->admin);
        $expected = ['perdue' => 'carte_perdue', 'volee' => 'carte_volee', 'compromise' => 'carte_revoquee'][$reason];
        pxControl($token)->assertOk()->assertJson(['valid' => false, 'reason' => $expected]);
        pxControl($token, ['type' => 'zone', 'id' => $this->zone])->assertJson(['valid' => false, 'reason' => $expected]);
    }
    expect(DB::table('alerts')->where('event_id', $this->event)->where('type', 'controle_refuse')->where('severity', 'critique')->count())->toBeGreaterThanOrEqual(3);
});

it('enregistre l’entrée d’un titulaire habilité, sans associer ni restituer la carte, puis sa sortie', function () {
    [$holder, $token] = pxCard('AILCT-02');
    pxGrant($holder, $this->event, $this->zone);
    $badge = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->first(['b.id', 'b.status', 'b.qr_token']);
    pxControl($token)->assertOk()->assertJson(['valid' => true, 'permanent' => ['entered' => true]]);
    expect(app(Presence::class)->count($this->event))->toBe(1);
    $second = pxControl($token)->assertJson(['valid' => true, 'permanent' => ['entered' => false]]);
    expect(app(Presence::class)->count($this->event))->toBe(1);
    pxControl($token, ['type' => 'zone', 'id' => $this->zone])->assertJson(['valid' => true, 'zone_ok' => true]);
    $this->travel(2)->seconds();
    pxExit($token)->assertOk()->assertJson(['ok' => true, 'permanent' => ['card_kept' => true]]);
    expect(app(Presence::class)->count($this->event))->toBe(0)
        ->and(DB::table('holder_cards')->where('holder_id', $holder)->value('status'))->toBe('active')
        ->and(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe($badge->status)
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->count())->toBe(0);
    pxExit($token)->assertStatus(200)->assertJson(['ok' => false, 'reason' => 'deja_sorti']);
    $types = DB::table('access_events')->where('event_id', $this->event)->orderBy('created_at')->pluck('type')->all();
    expect($types)->toContain('entree')->toContain('sortie');
});

it('réutilise la même carte et le même titulaire sur des événements successifs', function () {
    [$holder, $token] = pxCard();
    [$event2, $zone2] = pxEvent(PX_TOKEN_B);
    pxGrant($holder, $this->event, $this->zone);
    pxGrant($holder, $event2, $zone2);
    pxControl($token)->assertJson(['valid' => true]);
    $this->travel(2)->seconds();
    pxExit($token)->assertJson(['ok' => true]);
    $this->travel(2)->seconds();
    pxControl($token, null, PX_TOKEN_B)->assertJson(['valid' => true, 'permanent' => ['entered' => true]]);
    expect(app(Presence::class)->count($event2))->toBe(1)->and(app(Presence::class)->count($this->event))->toBe(0);
    $this->travel(2)->seconds();
    pxExit($token, PX_TOKEN_B)->assertJson(['ok' => true]);
    $this->travel(2)->seconds();
    // Retour sur le premier événement : nouvelle entrée, même fiche de participation.
    pxControl($token)->assertJson(['valid' => true, 'permanent' => ['entered' => true]]);
    expect(DB::table('visitors')->where('holder_id', $holder)->whereIn('event_id', [$this->event, $event2])->count())->toBe(2)->and(DB::table('holder_cards')->where('holder_id', $holder)->count())->toBe(1)
        ->and(DB::table('holder_cards')->where('holder_id', $holder)->value('status'))->toBe('active');
});

it('conserve l’habilitation et les présences après le remplacement de la carte', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    app(CardLifecycle::class)->replace($holder, 'perdue', null, $this->admin);
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'carte_perdue']);
    $newToken = pxToken($holder);
    expect($newToken)->not->toBe($token);
    pxControl($newToken)->assertJson(['valid' => true, 'permanent' => ['entered' => false]]);
    expect(DB::table('visitors')->where('holder_id', $holder)->where('event_id', $this->event)->count())->toBe(1);
});

it('refuse sans habilitation, hors zone habilitée, après expiration ou retrait, et ne déduit rien de la catégorie', function () {
    [$holder, $token] = pxCard('AILCT-01');
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'habilitation_absente']);
    $entitlement = pxGrant($holder, $this->event, $this->zone, '-1 hour', '+2 hours');
    pxControl($token)->assertJson(['valid' => true]);
    // Une autre zone de l'événement, non habilitée.
    $other = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $other, 'event_id' => $this->event, 'name' => 'Zone interdite', 'est_accueil' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('zone_policies')->insert(['zone_id' => $other, 'event_id' => $this->event, 'classification' => 'NEUTRE-TEST', 'permanent_allowed' => true, 'offline_entry_allowed' => false, 'created_at' => now(), 'updated_at' => now()]);
    pxControl($token, ['type' => 'zone', 'id' => $other])->assertJson(['valid' => false, 'reason' => 'habilitation_absente']);
    // Aucune catégorie AILCT ne donne de zone : la catégorie d'événement créée pour la participation n'a aucune zone.
    expect(DB::table('category_zone')->where('event_id', $this->event)->count())->toBe(0);
    // Retrait.
    app(HolderEntitlements::class)->revoke($entitlement, 'Fin de mission (fictif)', $this->admin);
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'habilitation_absente']);
    // Expiration : une habilitation dont la période est passée.
    pxGrant($holder, $this->event, $this->zone, '-3 hours', '-1 hour');
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'habilitation_expiree']);
    // Pas encore valable.
    app(HolderEntitlements::class)->revoke(DB::table('holder_entitlements')->where('holder_id', $holder)->whereNull('revoked_at')->value('id'), 'Essai (fictif)', $this->admin);
    pxGrant($holder, $this->event, $this->zone, '+1 hour', '+3 hours');
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'habilitation_absente']);
});

it('refuse par défaut une zone sans politique ou fermée aux titulaires permanents', function () {
    [$holder, $token] = pxCard();
    [$event, $zone] = pxEvent(PX_TOKEN_B, false);
    pxGrant($holder, $event, $zone);
    pxControl($token, null, PX_TOKEN_B)->assertJson(['valid' => false, 'reason' => 'zone_sans_politique']);
    app(HolderEntitlements::class)->savePolicy($event, $zone, 'NEUTRE-TEST', false, false, $this->admin);
    pxControl($token, ['type' => 'zone', 'id' => $zone], PX_TOKEN_B)->assertJson(['valid' => false, 'reason' => 'zone_sans_politique']);
    app(HolderEntitlements::class)->savePolicy($event, $zone, 'NEUTRE-TEST', true, false, $this->admin);
    pxControl($token, null, PX_TOKEN_B)->assertJson(['valid' => true]);
    expect(fn () => app(HolderEntitlements::class)->savePolicy($event, $zone, 'NEUTRE-TEST', false, true, $this->admin))->toThrow(ValidationException::class);
});

it('refuse un titulaire clos, un engagement terminé et un événement clos, mais laisse sortir', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    DB::table('permanent_holders')->where('id', $holder)->update(['engagement_ends_on' => now()->subDay()->toDateString(), 'engagement_starts_on' => now()->subMonth()->toDateString()]);
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'engagement_termine']);
    DB::table('permanent_holders')->where('id', $holder)->update(['engagement_ends_on' => null]);
    app(PermanentHolders::class)->close($holder, 'Motif fictif', $this->admin);
    pxControl($token)->assertJson(['valid' => false, 'reason' => 'titulaire_clos']);
    pxExit($token)->assertJson(['ok' => true]);
});

it('laisse sortir un titulaire dont la carte est révoquée et alerte, mais refuse une sortie sans entrée', function () {
    [$holder, $token] = pxCard();
    pxExit($token)->assertJson(['ok' => false, 'reason' => 'sortie_sans_entree']);
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    app(CardLifecycle::class)->revoke($holder, 'perdue', null, $this->admin);
    $this->travel(2)->seconds();
    pxExit($token)->assertJson(['ok' => true]);
    expect(app(Presence::class)->count($this->event))->toBe(0)->and(DB::table('alerts')->where('event_id', $this->event)->where('message', 'like', '%présentée à la sortie%')->exists())->toBeTrue();
});

// --- A06.4 hors ligne ------------------------------------------------------------------------------------------------------

it('refuse toute nouvelle entrée rejouée hors ligne tant qu’aucune confiance n’est approuvée', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    $known = now()->subMinutes(5)->toIso8601String();
    $seq = 0;
    $replay = function () use (&$seq, $token, $known) {
        return test()->postJson('/poste/api/sync', ['operations' => [['id' => (string) Str::uuid(), 'seq' => ++$seq, 'type' => 'controle', 'agent_id' => test()->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => 'PC:'.hash('sha256', $token), 'point' => null, 'local_valid' => true, 'known_at' => $known]]]], ['X-Device-Token' => PX_TOKEN_A, 'Accept' => 'application/json']);
    };
    $replay()->assertOk();
    expect(DB::table('access_events')->where('event_id', $this->event)->where('type', 'entree')->count())->toBe(0)
        ->and(DB::table('access_events')->where('event_id', $this->event)->where('details->raison', 'hors_ligne_non_autorise')->count())->toBe(1);
    $reply = $replay()->json('results.0') ?? $replay()->json();
    expect(json_encode($reply))->toContain('conflit');
});

it('juge l’ancienneté de la confiance hors ligne et la politique de zone à chaque décision rejouée', function () {
    [$holder, $token] = pxCard();
    [$event, $zone] = pxEvent(PX_TOKEN_B, true, true);
    pxGrant($holder, $event, $zone);
    app(PermanentAccess::class)->saveOfflineTrust(30, true, $this->admin);
    $agent = $this->agent;
    $seq = 0;
    $replay = function (string $knownAgo, ?array $point = null) use ($token, $agent, &$seq) {
        $seq++;

        return test()->postJson('/poste/api/sync', ['operations' => [['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => 'controle', 'agent_id' => $agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => 'PC:'.hash('sha256', $token), 'point' => $point, 'local_valid' => true, 'known_at' => now()->modify($knownAgo)->toIso8601String()]]]], ['X-Device-Token' => PX_TOKEN_B, 'Accept' => 'application/json']);
    };
    $replay('-45 minutes')->assertOk();
    expect(DB::table('access_events')->where('event_id', $event)->where('details->raison', 'confiance_expiree')->count())->toBe(1)->and(DB::table('access_events')->where('event_id', $event)->where('type', 'entree')->count())->toBe(0);
    $replay('-10 minutes')->assertOk();
    expect(DB::table('access_events')->where('event_id', $event)->where('type', 'entree')->count())->toBe(1);
    // Horloge à l'envers : la dernière synchronisation « dans le futur » n'est jamais crue.
    $replay('+10 minutes', ['type' => 'zone', 'id' => $zone])->assertOk();
    expect(DB::table('access_events')->where('event_id', $event)->where('details->raison', 'confiance_expiree')->count())->toBe(2);
    // Zone qui n'accepte pas l'entrée hors ligne.
    app(HolderEntitlements::class)->savePolicy($event, $zone, 'NEUTRE-TEST', true, false, $this->admin);
    $replay('-5 minutes', ['type' => 'zone', 'id' => $zone])->assertOk();
    expect(DB::table('access_events')->where('event_id', $event)->where('details->raison', 'hors_ligne_non_autorise')->count())->toBe(1);
});

it('rejoue une sortie hors ligne sans condition de confiance, sans doublon ni écrasement', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    $this->travel(2)->seconds();
    $op = ['id' => (string) Str::uuid(), 'seq' => 1, 'type' => 'sortie', 'agent_id' => $this->agent->id, 'client_time' => now()->subHours(20)->toIso8601String(), 'payload' => ['code' => 'PC:'.hash('sha256', $token), 'point' => null]];
    $send = fn () => $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => PX_TOKEN_A, 'Accept' => 'application/json']);
    $send()->assertOk();
    $send()->assertOk();
    expect(DB::table('access_events')->where('event_id', $this->event)->where('type', 'sortie')->count())->toBe(1)->and(app(Presence::class)->count($this->event))->toBe(0);
});

it('signale en conflit une entrée jugée valide hors ligne dont la carte a été révoquée entre-temps', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    app(PermanentAccess::class)->saveOfflineTrust(60, true, $this->admin);
    DB::table('zone_policies')->where('zone_id', $this->zone)->update(['offline_entry_allowed' => true]);
    app(CardLifecycle::class)->revoke($holder, 'volee', null, $this->admin);
    $response = $this->postJson('/poste/api/sync', ['operations' => [['id' => (string) Str::uuid(), 'seq' => 1, 'type' => 'controle', 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => 'PC:'.hash('sha256', $token), 'point' => null, 'local_valid' => true, 'known_at' => now()->subMinutes(2)->toIso8601String()]]]], ['X-Device-Token' => PX_TOKEN_A, 'Accept' => 'application/json'])->assertOk();
    expect(json_encode($response->json()))->toContain('conflit')->and(DB::table('access_events')->where('event_id', $this->event)->where('type', 'entree')->count())->toBe(0)
        ->and(DB::table('sync_conflicts')->where('event_id', $this->event)->count())->toBeGreaterThanOrEqual(1);
});

it('remet à la tablette uniquement des empreintes de jetons, les cartes révoquées et la confiance effective', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    [$lost, $lostToken] = pxCard();
    app(CardLifecycle::class)->revoke($lost, 'perdue', null, $this->admin);
    $snapshot = app(PermanentAccess::class)->snapshot($this->event);
    $json = json_encode($snapshot);
    expect($json)->not->toContain($token)->and($json)->not->toContain($lostToken)->and($snapshot['trust_minutes'])->toBe(0)
        ->and(collect($snapshot['cards'])->firstWhere('hash', hash('sha256', $lostToken))['status'])->toBe('perdue')
        ->and(collect($snapshot['holders'])->pluck('id')->all())->toBe([$holder]);
    app(PermanentAccess::class)->saveOfflineTrust(45, false, $this->admin);
    expect(app(PermanentAccess::class)->snapshot($this->event)['trust_minutes'])->toBe(0);
    app(PermanentAccess::class)->saveOfflineTrust(45, true, $this->admin);
    expect(app(PermanentAccess::class)->snapshot($this->event)['trust_minutes'])->toBe(45);
    expect(fn () => app(PermanentAccess::class)->saveOfflineTrust(5000, true, $this->admin))->toThrow(ValidationException::class);
});

// --- Non-régression ---------------------------------------------------------------------------------------------------------

it('ne change rien pour les badges et visiteurs temporaires du jeu de démonstration', function () {
    $this->seed(DemoSeeder::class);
    $before = DB::table('access_events')->count();
    $event = DemoSeeder::id('event');
    $unknown = bin2hex(random_bytes(32));
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', PX_TOKEN_A.'x')]);
    $this->postJson('/poste/api/controle', ['code' => 'IKOMA1:'.$unknown], pxHeaders(PX_TOKEN_A))->assertOk()->assertJson(['valid' => false, 'reason' => 'inconnu']);
    expect(DB::table('visitors')->whereNotNull('holder_id')->where('event_id', $event)->count())->toBe(0)->and(app(PermanentAccess::class)->resolveCard('IKOMA1:'.$unknown))->toBeNull();
});

it('garde le contrat QR IKOMA1 et ne lit jamais la catégorie institutionnelle pour décider', function () {
    $source = file_get_contents(app_path('Services/PermanentAccess.php'));
    $decide = substr($source, strpos($source, 'public function decide'), strpos($source, 'public function offlineRefusal') - strpos($source, 'public function decide'));
    expect($decide)->not->toContain('institutional_category')->and($decide)->not->toContain('institutional_code')->and(QrSvg::PREFIX)->toBe('IKOMA1:');
});

// --- Interfaces d'administration et évacuation ---------------------------------------------------------------------------------

it('affiche les cartes, habilitations, politiques, décisions et l’état de confiance des terminaux', function () {
    [$holder, $token] = pxCard();
    $admin = pxUser('administrateur');
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    $this->actingAs($admin)->get('/administration/habilitations?event='.$this->event)->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Habilitations')
        ->where('eventId', $this->event)->has('zones', 1)->where('zones.0.permanent_allowed', true)->has('entitlements', 1)->where('entitlements.0.identifier', DB::table('permanent_holders')->where('id', $holder)->value('identifier'))
        ->has('decisions', 1)->where('decisions.0.type', 'entree')->where('trust.effective', 0)->where('devices.0.state', 'aucune_confiance'));
    app(PermanentAccess::class)->saveOfflineTrust(30, true, $admin);
    DB::table('devices')->where('id', $this->device)->update(['last_synced_at' => now()->subMinutes(10)]);
    $this->get('/administration/habilitations?event='.$this->event)->assertInertia(fn ($p) => $p->where('devices.0.state', 'confiance_valide'));
    DB::table('devices')->where('id', $this->device)->update(['last_synced_at' => now()->subMinutes(50)]);
    $this->get('/administration/habilitations?event='.$this->event)->assertInertia(fn ($p) => $p->where('devices.0.state', 'confiance_expiree'));
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertInertia(fn ($p) => $p->component('Admin/CarteTitulaire')->has('cards', 1)->has('entitlements', 1)->where('cards.0.status', 'active'));
});

it('accorde, retire et configure par HTTP avec les permissions existantes, journalisé sans nom', function () {
    [$holder] = pxCard();
    $admin = pxUser('administrateur');
    $this->actingAs($admin);
    $this->post('/administration/habilitations', ['holder_id' => $holder, 'event_id' => $this->event, 'zone_id' => $this->zone, 'valid_from' => now()->subHour()->toIso8601String(), 'valid_until' => now()->addDay()->toIso8601String()])->assertRedirect();
    $id = DB::table('holder_entitlements')->value('id');
    // Chevauchement refusé, période inversée refusée.
    $this->post('/administration/habilitations', ['holder_id' => $holder, 'event_id' => $this->event, 'zone_id' => $this->zone, 'valid_from' => now()->toIso8601String(), 'valid_until' => now()->addHours(2)->toIso8601String()])->assertSessionHasErrors('valid_from');
    $this->post('/administration/habilitations', ['holder_id' => $holder, 'event_id' => $this->event, 'zone_id' => $this->zone, 'valid_from' => now()->addDays(3)->toIso8601String(), 'valid_until' => now()->addDays(2)->toIso8601String()])->assertSessionHasErrors('valid_until');
    $this->put('/administration/habilitations/zones/'.$this->zone, ['event_id' => $this->event, 'classification' => 'NEUTRE-B', 'permanent_allowed' => true, 'offline_entry_allowed' => true])->assertRedirect();
    expect(DB::table('zone_policies')->where('zone_id', $this->zone)->value('classification'))->toBe('NEUTRE-B');
    $this->put('/administration/habilitations/confiance', ['minutes' => 20, 'approved' => true])->assertRedirect();
    expect(app(PermanentAccess::class)->offlineTrust()['effective'])->toBe(20);
    $this->post('/administration/habilitations/'.$id.'/retirer', ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post('/administration/habilitations/'.$id.'/retirer', ['reason' => 'Fin de mission (fictif)'])->assertRedirect();
    expect(DB::table('holder_entitlements')->where('id', $id)->value('revoked_at'))->not->toBeNull();
    foreach (["UPDATE holder_entitlements SET valid_until = valid_until + interval '1 day'", 'DELETE FROM holder_entitlements', 'TRUNCATE holder_entitlements'] as $sql) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class);
    }
    $audit = DB::table('audit_logs')->whereIn('action', ['habilitation.accordee', 'habilitation.retiree', 'zone.politique_modifiee', 'titulaire.confiance_hors_ligne_modifiee'])->pluck('details')->implode(' ');
    expect($audit)->not->toContain('Personne Fictive')->and($audit)->not->toContain('Fin de mission');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(pxUser($role));
        $this->get('/administration/habilitations')->assertForbidden();
        $this->put('/administration/habilitations/zones/'.$this->zone, ['event_id' => $this->event, 'classification' => 'X', 'permanent_allowed' => true, 'offline_entry_allowed' => false])->assertForbidden();
        $this->put('/administration/habilitations/confiance', ['minutes' => 20, 'approved' => true])->assertForbidden();
        $this->post('/administration/habilitations/'.$id.'/retirer', ['reason' => 'x'])->assertForbidden();
    }
});

it('révoque et remplace par HTTP et ne touche à rien sans confirmation', function () {
    [$holder] = pxCard();
    $admin = pxUser('administrateur');
    $this->actingAs($admin);
    $this->post('/administration/titulaires/'.$holder.'/carte/revoquer', ['reason' => 'volee'])->assertSessionHasErrors('confirmation');
    expect(DB::table('holder_cards')->where('holder_id', $holder)->value('status'))->toBe('active');
    $this->post('/administration/titulaires/'.$holder.'/carte/remplacer', ['reason' => 'volee', 'note' => 'Vol (fictif)', 'confirmation' => true])->assertRedirect('/administration/titulaires/'.$holder.'/carte');
    expect(DB::table('holder_cards')->where('holder_id', $holder)->orderBy('rank')->pluck('status')->all())->toBe(['volee', 'active']);
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertInertia(fn ($p) => $p->has('cards', 2)->where('cards.0.rank', 2)->where('cards.1.status', 'volee'));
});

it('compte le titulaire présent à l’évacuation, sans aucune restitution', function () {
    [$holder, $token] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    pxControl($token)->assertJson(['valid' => true]);
    $name = DB::table('permanent_holders')->where('id', $holder)->value('full_name');
    $this->actingAs(pxUser('superviseur'))->getJson('/pilotage/api/evacuation?event='.$this->event)->assertOk()->assertJsonPath('total', 1);
    expect(json_encode($this->getJson('/pilotage/api/evacuation?event='.$this->event)->json()))->toContain($name);
});

it('interdit de supprimer une zone ou un événement portant des habilitations', function () {
    [$holder] = pxCard();
    pxGrant($holder, $this->event, $this->zone);
    expect(fn () => DB::transaction(fn () => DB::table('zones')->where('id', $this->zone)->delete()))->toThrow(QueryException::class);
    $report = app(DataLifecycle::class)->inspect('zone', $this->zone, $this->admin);
    expect($report['allowed'])->toBeFalse();
    $event = app(DataLifecycle::class)->inspect('evenement', $this->event, $this->admin);
    expect($event['allowed'])->toBeFalse();
});
