<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeRegistry;
use App\Services\BadgeService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * S05 — un badge physique circule entre activités : attribution temporaire, restitution explicite, mise à disposition, historique.
 * Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

const IA_TOKEN_B = '4444444444444444444444444444444444444444444444444444444444444444';

function iaUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif S05', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{id: string, category: string, device: string} une activité ouverte avec une catégorie et un poste */
function iaEvent(string $kind = 'stage', ?string $token = null): array
{
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $device = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité S05 '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => $kind, ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire S05', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    DB::table('devices')->insert(['id' => $device, 'event_id' => $id, 'name' => 'Tablette S05', 'station' => 'Poste S05', 'point_code' => 'poste_2', 'token_hash' => $token === null ? null : hash('sha256', $token), ...$at]);

    return ['id' => $id, 'category' => $category, 'device' => $device];
}

function iaReason(callable $fn): ?string
{
    try {
        $fn();
    } catch (BadgeRefused $e) {
        return $e->reason;
    }

    return null;
}

function iaVisitor(array $event, string $name): string
{
    $id = (string) Str::uuid();
    DB::table('visitors')->insert(['id' => $id, 'event_id' => $event['id'], 'full_name' => $name, 'category_id' => $event['category'], 'valid_from' => '2027-01-01 08:00:00+00', 'valid_until' => '2027-03-01 18:00:00+00',
        'source' => 'listé', 'status' => 'attendu', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = iaUser('administrateur');
    $this->agent = iaUser('agent_accueil');
    $this->actingAs($this->admin);
    $this->registry = app(BadgeRegistry::class);
    $this->badges = app(BadgeService::class);
    $this->stageA = iaEvent('stage');
    $this->visitB = iaEvent('visite', IA_TOKEN_B);
    DB::table('settings')->where('key', 'badge_reutilisable')->update(['value' => 'oui']);
    // trois badges dans l'activité A (codes B-001...), aucun dans B : pas de collision de codes
    $batch = app(BadgeLots::class)->generate($this->stageA['id'], $this->stageA['category'], 3, $this->admin);
    $this->lotA = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get();
});

it('met un badge disponible à disposition d’une autre activité, avec sa catégorie, sans rien perdre de son historique', function () {
    $badge = $this->lotA[0];
    $before = (int) DB::table('events')->where('id', $this->visitB['id'])->value('list_revision');
    $count = $this->registry->transfer([$badge->id], $this->visitB['id'], $this->visitB['category'], $this->visitB['device'], $this->admin);
    expect($count)->toBe(1);
    $row = DB::table('badges')->where('id', $badge->id)->first();
    expect($row->event_id)->toBe($this->visitB['id'])->and($row->category_id)->toBe($this->visitB['category'])->and($row->reserved_device_id)->toBe($this->visitB['device'])->and($row->status)->toBe('Disponible');
    $transfer = DB::table('badge_transfers')->where('badge_id', $badge->id)->first();
    expect($transfer->from_event_id)->toBe($this->stageA['id'])->and($transfer->to_event_id)->toBe($this->visitB['id']);
    expect((int) DB::table('events')->where('id', $this->visitB['id'])->value('list_revision'))->toBeGreaterThan($before);
    expect(DB::table('audit_logs')->where('action', 'badge.mis_a_disposition')->count())->toBe(1);
    // l'historique des mises à disposition ne se modifie pas
    expect(fn () => DB::transaction(fn () => DB::table('badge_transfers')->where('id', $transfer->id)->update(['actor_id' => $this->agent->id])))->toThrow(QueryException::class);
});

it('refuse de déplacer un badge attribué, déjà rattaché, de code déjà pris, ou une catégorie étrangère, et réserve l’action aux administrateurs', function () {
    $assigned = $this->lotA[0];
    $visitor = iaVisitor($this->stageA, 'Stagiaire S05 Zzyxwv');
    $this->badges->associer($assigned, $visitor, $this->agent, $this->stageA['device']);
    $refusal = fn (callable $fn) => iaReason($fn);
    expect($refusal(fn () => $this->registry->transfer([$assigned->id], $this->visitB['id'], $this->visitB['category'], null, $this->admin)))->toBe('non_disponible');
    expect($refusal(fn () => $this->registry->transfer([$this->lotA[1]->id], $this->stageA['id'], $this->stageA['category'], null, $this->admin)))->toBe('deja_rattache');
    expect($refusal(fn () => $this->registry->transfer([$this->lotA[1]->id], $this->visitB['id'], $this->stageA['category'], null, $this->admin)))->toBe('categorie');
    expect($refusal(fn () => $this->registry->transfer([$this->lotA[1]->id], $this->visitB['id'], $this->visitB['category'], $this->stageA['device'], $this->admin)))->toBe('poste_inconnu');
    expect($refusal(fn () => $this->registry->transfer([$this->lotA[1]->id], $this->visitB['id'], $this->visitB['category'], null, $this->agent)))->toBe('non_autorise');
    // un code déjà utilisé dans l'activité cible bloque la mise à disposition
    DB::table('badges')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->visitB['id'], 'code' => $this->lotA[2]->code, 'qr_token' => hash('sha256', 'ia-'.Str::random(8)), 'status' => 'Disponible', 'badge_batch_id' => $this->lotA[2]->badge_batch_id, 'created_at' => now(), 'updated_at' => now()]);
    expect($refusal(fn () => $this->registry->transfer([$this->lotA[2]->id], $this->visitB['id'], $this->visitB['category'], null, $this->admin)))->toBe('code_deja_utilise');
    // rien n'a bougé pour le badge attribué
    expect(DB::table('badges')->where('id', $assigned->id)->value('event_id'))->toBe($this->stageA['id'])->and(DB::table('badge_transfers')->count())->toBe(0);
    // la base refuse aussi un changement direct de rattachement d'un badge attribué
    expect(fn () => DB::transaction(fn () => DB::table('badges')->where('id', $assigned->id)->update(['event_id' => $this->visitB['id'], 'category_id' => $this->visitB['category'], 'reserved_device_id' => null])))->toThrow(QueryException::class);
});

it('fait circuler un badge : attribution dans A, restitution, mise à disposition de B, attribution dans B, restitution, avec tout l’historique et sans double attribution', function () {
    $badge = $this->lotA[0];
    $inA = iaVisitor($this->stageA, 'Stagiaire S05 Premier');
    $inB = iaVisitor($this->visitB, 'Visiteur S05 Second');
    $other = iaVisitor($this->visitB, 'Visiteur S05 Troisième');
    $this->badges->associer($badge, $inA, $this->agent, $this->stageA['device']);
    $this->badges->restituer($badge, $this->agent);
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Disponible');
    $this->travel(1)->minutes();
    // un badge de A ne s'attribue pas dans B tant qu'il n'a pas été mis à disposition
    expect(fn () => $this->badges->associer($badge, $inB, $this->agent, $this->visitB['device']))->toThrow(BadgeRefused::class, 'autre événement');
    $this->registry->transfer([$badge->id], $this->visitB['id'], $this->visitB['category'], $this->visitB['device'], $this->admin);
    $assignment = $this->badges->associer($badge, $inB, $this->agent, $this->visitB['device']);
    expect($assignment->event_id)->toBe($this->visitB['id']);
    // un badge attribué ne peut ni servir deux personnes ni changer d'activité
    expect(fn () => $this->badges->associer($badge, $other, $this->agent, $this->visitB['device']))->toThrow(BadgeRefused::class);
    expect(fn () => $this->registry->transfer([$badge->id], $this->stageA['id'], $this->stageA['category'], null, $this->admin))->toThrow(BadgeRefused::class);
    $this->badges->restituer($badge, $this->agent);
    $history = DB::table('badge_assignments')->where('badge_id', $badge->id)->orderBy('assigned_at')->get();
    expect($history)->toHaveCount(2)
        ->and($history->pluck('event_id')->all())->toBe([$this->stageA['id'], $this->visitB['id']])
        ->and($history->every(fn ($a) => $a->returned_at !== null))->toBeTrue();
    // les deux personnes gardent leur participation, indépendamment du badge
    expect(DB::table('visitors')->whereIn('id', [$inA, $inB])->count())->toBe(2);
});

it('restitue un badge depuis le poste de l’activité où il est attribué, jamais depuis un autre poste, avec vérification préalable sans écriture', function () {
    $badge = $this->lotA[0];
    $this->registry->transfer([$badge->id], $this->visitB['id'], $this->visitB['category'], $this->visitB['device'], $this->admin);
    $visitor = iaVisitor($this->visitB, 'Visiteur S05 Restitution');
    $this->badges->associer($badge, $visitor, $this->agent, $this->visitB['device']);
    $this->actingAs($this->agent);
    $headers = fn () => ['X-Device-Token' => IA_TOKEN_B, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
    $code = 'IKOMA1:'.DB::table('badges')->where('id', $badge->id)->value('qr_token');
    // encore présente : refus, aucune écriture
    DB::table('visitors')->where('id', $visitor)->update(['status' => 'present']);
    $this->postJson('/poste/api/restitution/verifier', ['code' => $code], $headers())->assertOk()->assertJson(['ok' => false, 'reason' => 'personne_presente']);
    $this->postJson('/poste/api/restitution', ['code' => $code], $headers())->assertOk()->assertJson(['ok' => false, 'reason' => 'personne_presente']);
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // sortie physique : la vérification n'écrit rien, la restitution est une action distincte
    DB::table('visitors')->where('id', $visitor)->update(['status' => 'sorti']);
    $check = $this->postJson('/poste/api/restitution/verifier', ['code' => $code], $headers())->assertOk()->assertJson(['ok' => true])->json();
    expect($check['person']['name'])->toBe('Visiteur S05 Restitution')->and(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // un poste d'une autre activité ne peut pas restituer ce badge
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', '3333333333333333333333333333333333333333333333333333333333333333')]);
    $this->postJson('/poste/api/restitution', ['code' => $code], ['X-Device-Token' => '3333333333333333333333333333333333333333333333333333333333333333', 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertOk()->assertJson(['ok' => false, 'reason' => 'autre_evenement']);
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // le bon poste restitue, une seule fois (rejeu de la même opération sans effet)
    $op = (string) Str::uuid();
    $ok = ['X-Device-Token' => IA_TOKEN_B, 'X-Operation-Id' => $op, 'Accept' => 'application/json'];
    $this->postJson('/poste/api/restitution', ['code' => $code], $ok)->assertOk()->assertJson(['ok' => true]);
    $this->postJson('/poste/api/restitution', ['code' => $code], $ok)->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
    expect(DB::table('audit_logs')->where('action', 'badge.restitue')->count())->toBe(1)
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNotNull('returned_at')->count())->toBe(1);
});

it('expose le registre aux administrateurs seulement, applique la mise à disposition et affiche l’historique du badge', function () {
    $badge = $this->lotA[0];
    $this->actingAs($this->agent);
    $this->get('/administration/badges/registre')->assertForbidden();
    $this->post('/administration/badges/mise-a-disposition', ['badges' => [$badge->id], 'event_id' => $this->visitB['id'], 'category_id' => $this->visitB['category']])->assertForbidden();
    $this->actingAs($this->admin);
    $this->get('/administration/badges/registre?activite='.$this->visitB['id'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/BadgeRegistre')->where('badges', fn ($rows) => collect($rows)->pluck('id')->contains($badge->id)));
    $this->post('/administration/badges/mise-a-disposition', ['badges' => [$badge->id], 'event_id' => $this->visitB['id'], 'category_id' => $this->visitB['category']])->assertRedirect();
    expect(DB::table('badges')->where('id', $badge->id)->value('event_id'))->toBe($this->visitB['id']);
    $this->post('/administration/badges/mise-a-disposition', ['badges' => [$badge->id], 'event_id' => $this->visitB['id'], 'category_id' => $this->visitB['category']])->assertSessionHasErrors('badges');
    $this->get('/administration/badges/'.$badge->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/BadgeDetails')->has('transfers', 1)->has('history', 0));
});

it('réserve la restitution au poste 2 : un poste de soute, un poste non identifié ou un autre rôle sont refusés par le serveur', function () {
    $badge = $this->lotA[0];
    $this->registry->transfer([$badge->id], $this->visitB['id'], $this->visitB['category'], $this->visitB['device'], $this->admin);
    $visitor = iaVisitor($this->visitB, 'Visiteur S05 Poste');
    $this->badges->associer($badge, $visitor, $this->agent, $this->visitB['device']);
    DB::table('visitors')->where('id', $visitor)->update(['status' => 'sorti']);
    $this->actingAs($this->agent);
    $code = 'IKOMA1:'.DB::table('badges')->where('id', $badge->id)->value('qr_token');
    $headers = fn () => ['X-Device-Token' => IA_TOKEN_B, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
    // un poste de soute n'a que la capacité « scan »
    DB::table('devices')->where('id', $this->visitB['device'])->update(['point_code' => 'soute']);
    $this->postJson('/poste/api/restitution/verifier', ['code' => $code], $headers())->assertForbidden()->assertJson(['code' => 'poste_non_habilite']);
    $this->postJson('/poste/api/restitution', ['code' => $code], $headers())->assertForbidden()->assertJson(['code' => 'poste_non_habilite']);
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé');
    // le poste 2 restitue
    DB::table('devices')->where('id', $this->visitB['device'])->update(['point_code' => 'poste_2']);
    $this->postJson('/poste/api/restitution', ['code' => $code], $headers())->assertOk()->assertJson(['ok' => true]);
    expect(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Disponible');
});
