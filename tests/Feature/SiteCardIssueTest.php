<?php

use App\Models\BadgeBatch;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\CardLifecycle;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $this->admin->assignRole('administrateur');
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->cards = app(HolderCards::class);
    $this->newHolder = fn (): string => app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => 'AILCT-01'], $this->admin);
});

it('émet la carte sans événement : ni registre technique, ni lot, ni catégorie, ni participation', function () {
    $holder = ($this->newHolder)();
    $card = $this->cards->issue($holder, $this->admin, false);
    $row = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.id', $card)->first(['k.event_id as card_event', 'b.event_id', 'b.badge_batch_id', 'b.category_id', 'b.code', 'b.status', 'b.id as badge']);
    expect($row->card_event)->toBeNull()->and($row->event_id)->toBeNull()->and($row->badge_batch_id)->toBeNull()->and($row->category_id)->toBeNull()
        ->and($row->status)->toBe('Disponible')->and($row->code)->toBe('B-001')
        ->and(DB::table('events')->count())->toBe(0)
        ->and(DB::table('visitors')->count())->toBe(0)
        ->and(DB::table('categories')->count())->toBe(0)
        ->and(DB::table('badge_batches')->count())->toBe(0);
    $ledger = DB::table('badge_issuances')->where('badge_id', $row->badge)->first();
    expect($ledger->event_id)->toBeNull()->and($ledger->support_type)->toBe('carte_permanente')->and($ledger->holder_id)->toBe($holder);
});

it('numérote les cartes du site de façon continue, sans réutiliser un code retiré', function () {
    $codes = [];
    foreach ([1, 2] as $n) {
        $holder = ($this->newHolder)();
        $this->cards->issue($holder, $this->admin, false);
        $codes[] = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->value('b.code');
    }
    expect($codes)->toBe(['B-001', 'B-002']);
    DB::table('badges')->where('code', 'B-002')->delete();
    $third = app(BadgeLots::class)->issueSiteCard('Essai', $this->admin);
    expect($third['code'])->toBe('B-003');
    expect(fn () => DB::table('badges')->insert(['id' => (string) Str::uuid7(), 'event_id' => null, 'code' => 'B-001', 'qr_token' => bin2hex(random_bytes(32)), 'status' => 'Disponible', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('dépasse les codes des anciennes cartes du registre technique pour qu’aucun code ne se répète au rattachement', function () {
    $context = app(PermanentHolders::class)->createPermanentContext($this->admin);
    $category = (string) Str::uuid();
    DB::table('categories')->insert(['id' => $category, 'event_id' => $context, 'name' => 'Ancien registre', 'color' => '#336699', 'priority_circuit' => false, 'created_at' => now(), 'updated_at' => now()]);
    $batch = BadgeBatch::create(['event_id' => $context, 'category_id' => $category, 'name' => 'Ancienne carte', 'quantity' => 1, 'margin' => 0]);
    DB::table('badges')->insert(['id' => (string) Str::uuid7(), 'event_id' => $context, 'category_id' => $category, 'badge_batch_id' => $batch->id, 'code' => 'B-007', 'qr_token' => bin2hex(random_bytes(32)), 'status' => 'Disponible', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(BadgeLots::class)->issueSiteCard('Essai', $this->admin)['code'])->toBe('B-008');
});

it('imprime, remplace et révoque une carte du site, et la retrouve au contrôle par son QR', function () {
    $holder = ($this->newHolder)();
    $this->cards->issue($holder, $this->admin);
    $token = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->where('k.status', 'active')->value('b.qr_token');
    expect($this->cards->recordPrint($holder, null, $this->admin))->toBe('emission')
        ->and($this->cards->recordPrint($holder, 'Carte abîmée', $this->admin))->toBe('reimpression');
    $found = app(PermanentAccess::class)->resolveCard(QrSvg::payload($token));
    expect($found)->not->toBeNull()->and($found->holder_id)->toBe($holder)->and($found->card_status)->toBe('active');
    $new = app(CardLifecycle::class)->replace($holder, 'perdue', null, $this->admin);
    $rows = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->orderBy('k.rank')->get(['k.status', 'b.event_id', 'b.code']);
    expect($rows)->toHaveCount(2)->and($rows[0]->status)->toBe('perdue')->and($rows[1]->status)->toBe('active')
        ->and($rows->every(fn ($r) => $r->event_id === null))->toBeTrue()->and($rows[0]->code)->not->toBe($rows[1]->code)
        ->and($new)->toBeString();
    expect(app(PermanentAccess::class)->resolveCard(QrSvg::payload($token))->card_status)->toBe('perdue');
});
