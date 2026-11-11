<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Visits;
use Illuminate\Support\Facades\DB;

/**
 * Consultations en lecture seule du poste (S10), en ligne uniquement : visites attendues au poste d'accueil, refus et incidents pertinents pour le poste.
 * Aucune écriture, aucun droit nouveau : les capacités de poste existantes (S02) décident, et le serveur les revérifie à chaque appel.
 */
final class PosteConsultation
{
    public function __construct(private readonly Visits $visits) {}

    /**
     * Visites annoncées pas encore arrivées (attendues ou en retard) : poste d'accueil seulement.
     *
     * @return list<array<string, mixed>>
     */
    public function expected(Device $poste, User $agent): array
    {
        PostCapabilities::require($poste, $agent, 'accueil');
        $rows = array_values(array_filter($this->visits->expected($poste->event_id)['rows'], fn (array $r): bool => in_array($r['state'], ['attendu', 'en_retard'], true)));

        return array_map(fn (array $r): array => ['id' => $r['id'], 'full_name' => $r['full_name'], 'organisation' => $r['organisation'], 'expected_at' => $r['expected_at'], 'state' => $r['state'], 'category' => $r['category'], 'host' => $r['host'], 'plates' => $r['plates']], array_slice($rows, 0, 50));
    }

    /**
     * Refus et incidents : les contrôles refusés enregistrés à CE poste, et, au poste d'accueil, les accès refusés de l'activité.
     * Le poste de la soute ne voit que ses propres refus (pas les décisions d'admission, qui ne sont pas les siennes).
     *
     * @return array{controls: list<array<string, mixed>>, refusals: list<array<string, mixed>>, can_see_refusals: bool}
     */
    public function incidents(Device $poste, User $agent): array
    {
        $reception = true;
        try {
            PostCapabilities::require($poste, $agent, 'accueil');
        } catch (DeskRefused) {
            PostCapabilities::require($poste, $agent, 'scan');
            $reception = false;
        }
        $controls = DB::table('access_events as e')->leftJoin('visitors as v', 'v.id', '=', 'e.visitor_id')->leftJoin('badges as b', 'b.id', '=', 'e.badge_id')
            ->where('e.event_id', $poste->event_id)->where('e.type', 'controle')->whereRaw("e.details->>'poste_id' = ?", [$poste->id])
            ->where(fn ($q) => $q->whereRaw("coalesce(e.details->>'verdict', '') <> 'valide'")->orWhereRaw("e.details->>'zone_ok' = 'false'"))
            ->orderByDesc('e.occurred_at')->orderByDesc('e.seq')->limit(10)
            ->get(['e.id', 'e.occurred_at', 'e.checkpoint', 'v.full_name', 'b.code as badge_code', DB::raw("e.details->>'raison' as reason"), DB::raw("e.details->>'zone_ok' as zone_ok")])
            ->map(fn ($c) => ['id' => $c->id, 'occurred_at' => $c->occurred_at, 'checkpoint' => $c->checkpoint, 'full_name' => $c->full_name, 'badge_code' => $c->badge_code, 'reason' => $c->zone_ok === 'false' ? 'zone_non_autorisee' : $c->reason, 'poste_id' => $poste->id])
            ->values()->all();
        $refusals = [];
        if ($reception) {
            $refusals = DB::table('refusals as f')->join('visitors as v', 'v.id', '=', 'f.visitor_id')->where('v.event_id', $poste->event_id)->orderByDesc('f.refused_at')->limit(10)
                ->get(['f.id', 'f.reason', 'f.refused_at', 'f.decision', 'v.id as visitor_id', 'v.full_name'])
                ->map(fn ($r) => ['id' => $r->id, 'reason' => $r->reason, 'refused_at' => $r->refused_at, 'decision' => $r->decision, 'visitor_id' => $r->visitor_id, 'full_name' => $r->full_name])->values()->all();
        }

        return ['controls' => $controls, 'refusals' => $refusals, 'can_see_refusals' => $reception];
    }
}
