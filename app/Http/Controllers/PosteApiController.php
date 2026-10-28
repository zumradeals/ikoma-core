<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Poste\Desk;
use App\Services\Poste\Operations;
use App\Services\Poste\SiteControl;
use App\Services\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Routes JSON du poste d'accueil. Les permissions sont portées par les routes ; chaque écriture est idempotente (X-Operation-Id). */
final class PosteApiController
{
    public function __construct(private readonly Desk $desk, private readonly Operations $operations, private readonly SiteControl $site, private readonly Presence $presence) {}

    public function postes(): JsonResponse
    {
        return response()->json(['postes' => DB::table('devices as d')->join('events as e', 'e.id', '=', 'd.event_id')->orderBy('e.name')->orderBy('d.name')->get(['d.id', 'd.name', 'd.station', 'd.event_id', 'e.name as event_name'])]);
    }

    public function contexte(Request $r): JsonResponse
    {
        return response()->json($this->desk->context($this->poste($r)));
    }

    public function recherche(Request $r): JsonResponse
    {
        $data = $r->validate(['q' => 'required|string|max:160']);

        return response()->json(['results' => $this->desk->search($this->poste($r), $data['q'])]);
    }

    public function fiche(Request $r, string $visitor): JsonResponse
    {
        return response()->json($this->desk->fiche($this->poste($r), $visitor));
    }

    public function identite(Request $r): JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'identity_checked' => 'required|boolean', 'photo' => 'required|file|max:1024']);
        $bytes = (string) $r->file('photo')->get();
        $poste = $this->poste($r);

        return $this->operations->run($r, 'identite', $data['visitor_id'].'|'.(int) $r->boolean('identity_checked').'|'.hash('sha256', $bytes), fn () => $this->desk->saveIdentity($poste, $r->user(), $data['visitor_id'], $r->boolean('identity_checked'), $bytes));
    }

    public function valider(Request $r): JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'avis' => 'nullable|in:hote,superviseur']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'valider', $data['visitor_id'].'|'.($data['avis'] ?? ''), fn () => $this->desk->validate($poste, $r->user(), $data['visitor_id'], $data['avis'] ?? null));
    }

    public function refuser(Request $r): JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'motif' => 'required|string|max:500']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'refuser', $data['visitor_id'].'|'.$data['motif'], fn () => $this->desk->refuse($poste, $r->user(), $data['visitor_id'], $data['motif']));
    }

    public function badge(Request $r): JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'code' => 'required|string|max:100']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'badge', $data['visitor_id'].'|'.$data['code'], fn () => $this->desk->assignBadge($poste, $r->user(), $data['visitor_id'], $data['code']));
    }

    public function entree(Request $r): JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'required|uuid', 'duration_ms' => 'nullable|integer|min:0']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'entree', $data['visitor_id'], fn () => $this->desk->enter($poste, $r->user(), $data['visitor_id'], (int) ($data['duration_ms'] ?? 0)));
    }

    public function nonListe(Request $r): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|max:160', 'organisation' => 'nullable|string|max:160', 'host_id' => 'required|uuid', 'category_id' => 'required|uuid']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'non_liste', implode('|', [$data['name'], $data['organisation'] ?? '', $data['host_id'], $data['category_id']]), fn () => $this->desk->createUnlisted($poste, $r->user(), $data['name'], $data['organisation'] ?? null, $data['host_id'], $data['category_id']));
    }

    public function controle(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:100', 'point' => 'nullable|array', 'point.type' => 'required_with:point|in:zone,poste', 'point.id' => 'required_with:point|uuid']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'controle', hash('sha256', $data['code']).'|'.json_encode($data['point'] ?? null), fn () => $this->site->control($poste, $r->user(), $data['code'], $data['point'] ?? null));
    }

    public function sortie(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:100', 'point' => 'nullable|array', 'point.type' => 'required_with:point|in:zone,poste', 'point.id' => 'required_with:point|uuid']);
        $poste = $this->poste($r);

        return $this->operations->run($r, 'sortie', hash('sha256', $data['code']).'|'.json_encode($data['point'] ?? null), fn () => $this->site->exit($poste, $r->user(), $data['code'], $data['point'] ?? null));
    }

    public function presents(Request $r): JsonResponse
    {
        $event = $this->poste($r)->event_id;

        return response()->json(['count' => $this->presence->count($event), 'as_of' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')]);
    }

    private function poste(Request $r): Device
    {
        return $r->attributes->get('poste');
    }
}
