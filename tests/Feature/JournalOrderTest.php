<?php

use App\Services\Presence;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    // Visiteur sans aucun mouvement : le compte de départ de son événement est la référence.
    $this->visitor = DB::table('visitors as v')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as e')->whereColumn('e.visitor_id', 'v.id'))->first();
    $this->baseline = app(Presence::class)->count($this->visitor->event_id);
});

function joEvent(object $visitor, string $id, string $type, string $at): void
{
    DB::table('access_events')->insert(['id' => $id, 'event_id' => $visitor->event_id, 'visitor_id' => $visitor->id, 'badge_id' => null, 'actor_id' => null, 'type' => $type, 'checkpoint' => 'Point fictif',
        'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
}

it('départage une entrée et une sortie de la même seconde par l’ordre d’insertion, jamais par l’identifiant', function () {
    $at = '2027-01-10 10:00:00+00';
    // L'identifiant de la sortie est plus petit que celui de l'entrée : l'ancien départage par identifiant aurait compté la personne présente.
    joEvent($this->visitor, 'ffffffff-0000-4000-8000-000000000001', 'entree', $at);
    joEvent($this->visitor, '00000000-0000-4000-8000-000000000002', 'sortie', $at);

    expect(app(Presence::class)->visitorPresent($this->visitor->id))->toBeFalse();
    expect(app(Presence::class)->count($this->visitor->event_id))->toBe($this->baseline);
});

it('compte présente la personne dont la dernière entrée suit la sortie de la même seconde', function () {
    $at = '2027-01-10 10:00:00+00';
    joEvent($this->visitor, '00000000-0000-4000-8000-000000000003', 'sortie', $at);
    joEvent($this->visitor, 'ffffffff-0000-4000-8000-000000000004', 'entree', $at);

    expect(app(Presence::class)->visitorPresent($this->visitor->id))->toBeTrue();
    expect(app(Presence::class)->count($this->visitor->event_id))->toBe($this->baseline + 1);
});
