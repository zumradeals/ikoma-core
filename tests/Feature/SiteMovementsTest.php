<?php

use App\Models\User;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use App\Services\Poste\DeskRefused;
use App\Services\Presence;
use App\Services\SiteMovements;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif mouvements du site', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive Mouvement '.Str::random(6), 'institutional_category' => 'AILCT-01'], $this->admin);
    $this->movements = app(SiteMovements::class);
});

it('enregistre une entrée du site sans événement, tracée, et la compte présente', function () {
    $id = $this->movements->record($this->holder, 'entree', 'Entrée principale fictive', $this->admin);
    $row = DB::table('access_events')->where('id', $id)->first();
    expect($row->event_id)->toBeNull()->and($row->visitor_id)->toBeNull()->and($row->holder_id)->toBe($this->holder)->and($row->type)->toBe('entree');
    expect($this->movements->count())->toBe(1)->and($this->movements->present()[0]->id)->toBe($this->holder);
    expect(DB::table('audit_logs')->where('action', 'entree.site')->count())->toBe(1);
});

it('recalcule la présence : sortie puis nouvelle entrée, une personne n’est comptée qu’une fois', function () {
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin, now()->subHours(3));
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin, now()->subHours(2));
    expect($this->movements->count())->toBe(1);
    $this->movements->record($this->holder, 'sortie', 'Sortie fictive', $this->admin, now()->subHour());
    expect($this->movements->count())->toBe(0);
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin, now());
    expect($this->movements->count())->toBe(1);
});

it('corrige un mouvement du site une seule fois et recalcule la présence', function () {
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin, now()->subHour());
    $exit = $this->movements->record($this->holder, 'sortie', 'Sortie fictive', $this->admin, now());
    expect($this->movements->count())->toBe(0);
    $this->movements->correct($exit, $this->admin, 'Sortie saisie par erreur');
    expect($this->movements->count())->toBe(1);
    expect(fn () => $this->movements->correct($exit, $this->admin, 'Encore'))->toThrow(DeskRefused::class);
    expect(fn () => $this->movements->correct($exit, $this->admin, ' '))->toThrow(DeskRefused::class);
});

it('refuse en base un mouvement mêlant activité et site, ou sans personne identifiée', function () {
    $event = (string) DB::table('events')->where('is_permanent', false)->value('id');
    $base = ['id' => '', 'event_id' => null, 'visitor_id' => null, 'badge_id' => null, 'holder_id' => null, 'actor_id' => null, 'type' => 'entree', 'checkpoint' => null, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    $insert = fn (array $row) => fn () => DB::transaction(fn () => DB::table('access_events')->insert($row + ['id' => (string) Str::uuid()] + $base));
    expect($insert(['event_id' => $event, 'holder_id' => $this->holder]))->toThrow(QueryException::class);
    expect($insert(['holder_id' => null, 'type' => 'entree']))->toThrow(QueryException::class);
    expect($insert(['holder_id' => null, 'type' => 'sortie']))->toThrow(QueryException::class);
    expect($insert(['holder_id' => $this->holder, 'type' => 'inconnu']))->toThrow(QueryException::class);
    expect($insert(['holder_id' => null, 'type' => 'controle']))->not->toThrow(QueryException::class);
});

it('refuse un mouvement du site sans personne pour une entrée, mais accepte un contrôle sur carte inconnue', function () {
    expect(fn () => $this->movements->record(null, 'entree', 'Point', $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->movements->record($this->holder, 'badge', 'Point', $this->admin))->toThrow(ValidationException::class);
    $this->movements->record(null, 'controle', 'Point', $this->admin);
    expect($this->movements->count())->toBe(0);
});

it('ne laisse pas supprimer un titulaire qui a des mouvements du site', function () {
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin);
    expect(fn () => DB::transaction(fn () => DB::table('permanent_holders')->where('id', $this->holder)->delete()))->toThrow(QueryException::class);
});

it('garde les présences d’activité intactes : la correction d’activité refuse un mouvement du site', function () {
    $id = $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin);
    expect(fn () => app(Presence::class)->corriger($id, $this->admin, 'Essai'))->toThrow(DeskRefused::class);
    expect($this->movements->count())->toBe(1);
});

it('affiche les présents du site dans « Site et accès » pour qui a le droit de consulter les présences', function () {
    $this->movements->record($this->holder, 'entree', 'Entrée principale fictive', $this->admin);
    $this->get('/administration/site')->assertOk()->assertInertia(fn ($p) => $p->has('site_presence', 1)->where('site_presence.0.point', 'Entrée principale fictive'));
});

it('ne change pas les chiffres des activités : les mouvements du site n’apparaissent pas dans la présence d’une activité', function () {
    $event = (string) DB::table('events')->where('is_permanent', false)->value('id');
    $before = app(Presence::class)->count($event);
    $this->movements->record($this->holder, 'entree', 'Entrée fictive', $this->admin);
    expect(app(Presence::class)->count($event))->toBe($before);
});
