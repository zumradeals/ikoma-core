<?php

namespace App\Http\Controllers;

use App\Services\Alerts;
use App\Services\Audit;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\Pilotage;
use App\Services\Poste\DeskRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écrans du superviseur (smartphone d'abord). Chaque route porte sa permission ; le superviseur n'associe aucun badge et ne gère aucun compte.
 * Les noms de personnes ne sont affichés qu'ici, à la consultation : ni dans les alertes, ni dans le journal.
 */
final class PilotageController
{
    public function __construct(private readonly Pilotage $pilotage, private readonly Alerts $alerts, private readonly Audit $audit, private readonly BadgeService $badges) {}

    private function event(Request $r): ?object
    {
        return $this->pilotage->event($r->query('event') ? (string) $r->query('event') : null);
    }

    /** @return array<string, mixed> */
    private function shared(?object $event): array
    {
        return [
            'event' => $event ? ['id' => $event->id, 'name' => $event->name] : null,
            'events' => DB::table('events')->orderByDesc('starts_at')->get(['id', 'name']),
            'openAlerts' => $event ? $this->pilotage->openAlertCount($event->id) : 0,
        ];
    }

    public function index(Request $r): Response
    {
        $event = $this->event($r);

        // Fonctions : un rechargement partiel (only: counters, alerts) ne calcule que ce qui est demandé.
        return Inertia::render('Pilotage/Index', [
            ...$this->shared($event),
            'counters' => fn () => $event ? $this->pilotage->counters($event->id) : null,
            'alerts' => fn () => $event ? $this->pilotage->alerts($event->id, 'a_traiter', 5) : [],
            'overview' => fn () => $event ? $this->pilotage->overview($event->id) : null,
        ]);
    }

    public function alertes(Request $r): Response
    {
        $state = $r->query('etat') === 'prises' ? 'prises' : 'a_traiter';
        $event = $this->event($r);

        return Inertia::render('Pilotage/Alertes', [...$this->shared($event), 'state' => $state, 'alerts' => fn () => $event ? $this->pilotage->alerts($event->id, $state) : []]);
    }

    public function prendreEnCompte(Request $r, string $alert): RedirectResponse
    {
        $this->alerts->acknowledge($alert, $r->user());

        return back();
    }

    public function presents(Request $r): Response
    {
        $filters = $r->validate(['q' => 'nullable|string|max:80', 'category' => 'nullable|uuid', 'zone' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:1000']);
        $event = $this->event($r);
        $page = (int) ($filters['page'] ?? 1);
        $total = $event ? $this->pilotage->presentCount($event->id, $filters) : 0;

        return Inertia::render('Pilotage/Presents', [
            ...$this->shared($event), 'filters' => ['q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '', 'zone' => $filters['zone'] ?? ''],
            'rows' => $event ? $this->pilotage->presentRows($event->id, $filters, Pilotage::PER_PAGE, ($page - 1) * Pilotage::PER_PAGE) : [], 'total' => $total, 'page' => $page, 'perPage' => Pilotage::PER_PAGE,
            'categories' => $event ? DB::table('categories')->where('event_id', $event->id)->orderBy('name')->get(['id', 'name', 'color', 'priority_circuit']) : [],
            'zones' => $event ? DB::table('zones')->where('event_id', $event->id)->orderBy('name')->pluck('name') : [],
        ]);
    }

    public function visiteur(Request $r, string $visitor): Response
    {
        $row = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')->leftJoin('groups as g', 'g.id', '=', 'v.group_id')->where('v.id', $visitor)
            ->first(['v.id', 'v.event_id', 'v.full_name', 'v.organisation', 'v.status', 'v.source', 'v.access_note', 'v.valid_from', 'v.valid_until', 'c.name as category_name', 'c.color', 'c.priority_circuit', 'h.name as host_name', 'g.name as group_name']) ?? throw new DeskRefused('visiteur_inconnu', 'Visiteur introuvable.', 404);
        $badge = DB::table('badge_assignments as a')->join('badges as b', 'b.id', '=', 'a.badge_id')->where('a.visitor_id', $visitor)->whereNull('a.returned_at')->whereNull('a.cancelled_at')->first(['b.id', 'b.code', 'b.status']);
        $cancelled = DB::table('badge_assignments as a')->join('badges as b', 'b.id', '=', 'a.badge_id')->where('a.visitor_id', $visitor)->whereNotNull('a.cancelled_at')->orderByDesc('a.cancelled_at')->first(['b.code', 'a.cancelled_at', 'a.cancellation_reason']);
        $refusal = DB::table('refusals')->where('visitor_id', $visitor)->orderByDesc('refused_at')->first(['id', 'reason', 'refused_at', 'decision', 'supervisor_decision']);
        $present = DB::table('access_events as e')->where('e.visitor_id', $visitor)->whereIn('e.type', ['entree', 'sortie'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as c')->whereColumn('c.corrige_id', 'e.id'))->orderByDesc('e.occurred_at')->orderByDesc('e.created_at')->value('e.type') === 'entree';

        return Inertia::render('Pilotage/Visiteur', [
            ...$this->shared((object) ['id' => $row->event_id, 'name' => (string) DB::table('events')->where('id', $row->event_id)->value('name')]),
            'visitor' => ['id' => $row->id, 'full_name' => $row->full_name, 'organisation' => $row->organisation, 'status' => $row->status ?? 'attendu', 'present' => $present, 'source' => $row->source, 'access_note' => $row->access_note, 'host' => $row->host_name, 'group' => $row->group_name, 'valid_from' => $row->valid_from, 'valid_until' => $row->valid_until, 'category' => ['name' => $row->category_name, 'color' => $row->color, 'priority_circuit' => (bool) $row->priority_circuit]],
            'badge' => $badge ? ['id' => $badge->id, 'code' => $badge->code, 'status' => $badge->status] : null,
            'toRebadge' => $badge === null && $cancelled !== null && ! in_array($row->status, ['sorti', 'refuse'], true) ? ['code' => $cancelled->code, 'at' => $cancelled->cancelled_at, 'reason' => $cancelled->cancellation_reason] : null,
            'refusal' => $refusal ? (array) $refusal : null,
            'permissions' => ['annuler' => $r->user()->can('badges.annuler'), 'decider' => $r->user()->can('litiges.decider')],
        ]);
    }

    /**
     * Annulation d'un badge par le superviseur : motif obligatoire, confirmation explicite (action irréversible). L'association d'un nouveau badge
     * reste à l'agent d'accueil, prévenu par la fiche « à rebadger » et l'alerte.
     */
    public function annulerBadge(Request $r): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['visitor_id' => 'nullable|uuid', 'code' => 'nullable|string|max:100', 'event_id' => 'nullable|uuid', 'motif' => 'nullable|string|max:500', 'confirmation' => 'nullable|boolean']);
        if (empty($data['visitor_id']) && empty($data['code'])) {
            return $this->refuse($r, 'badge_requis', 'Indiquez le visiteur ou le code du badge à annuler.');
        }
        if (trim((string) ($data['motif'] ?? '')) === '') {
            return $this->refuse($r, 'motif_obligatoire', 'Le motif d’annulation est obligatoire.');
        }
        if (! $r->boolean('confirmation')) {
            return $this->refuse($r, 'confirmation_requise', 'Confirmez l’annulation : elle est irréversible.');
        }
        if (! empty($data['visitor_id'])) {
            $badge = DB::table('badge_assignments')->where('visitor_id', $data['visitor_id'])->whereNull('returned_at')->whereNull('cancelled_at')->value('badge_id');
        } else {
            $ids = DB::table('badges')->whereRaw('upper(code) = ?', [mb_strtoupper(trim((string) $data['code']))])->when(! empty($data['event_id']), fn ($q) => $q->where('event_id', $data['event_id']))->limit(2)->pluck('id');
            if ($ids->count() > 1) {
                return $this->refuse($r, 'evenement_requis', 'Plusieurs événements portent ce code : choisissez l’événement.');
            }
            $badge = $ids->first();
        }
        if ($badge === null) {
            return $this->refuse($r, 'badge_inconnu', 'Aucun badge actif à annuler pour cette demande.');
        }
        try {
            $this->badges->annuler($badge, $r->user(), (string) $data['motif']);
        } catch (BadgeRefused $e) {
            return $this->refuse($r, $e->reason, $e->getMessage());
        }

        return $r->expectsJson() && ! $r->header('X-Inertia') ? response()->json(['ok' => true]) : back();
    }

    private function refuse(Request $r, string $code, string $message): RedirectResponse|JsonResponse
    {
        return $r->expectsJson() && ! $r->header('X-Inertia')
            ? response()->json(['code' => $code, 'message' => $message], 422)
            : back()->withErrors(['pilotage' => $message]);
    }

    public function litiges(Request $r): Response
    {
        $event = $this->event($r);
        $rows = $event ? DB::table('refusals as f')->join('visitors as v', 'v.id', '=', 'f.visitor_id')->join('categories as c', 'c.id', '=', 'v.category_id')->where('v.event_id', $event->id)->orderByRaw('f.decision IS NOT NULL')->orderByDesc('f.refused_at')->limit(200)
            ->get(['f.id', 'f.reason', 'f.refused_at', 'f.decision', 'f.supervisor_decision', 'f.decided_at', 'v.id as visitor_id', 'v.full_name', 'v.status', 'c.name as category_name', 'c.color']) : collect();

        return Inertia::render('Pilotage/Litiges', [...$this->shared($event), 'rows' => $rows, 'canDecide' => $r->user()->can('litiges.decider')]);
    }

    public function decider(Request $r, string $refusal): RedirectResponse
    {
        $data = $r->validate(['decision' => ['required', Rule::in(['maintenu', 'autorise', 'a_revoir'])], 'note' => 'nullable|string|max:500']);
        $note = trim((string) ($data['note'] ?? ''));
        if ($data['decision'] === 'autorise' && $note === '') {
            return back()->withErrors(['pilotage' => 'Indiquez les conditions de l’autorisation.']);
        }
        DB::transaction(function () use ($r, $refusal, $data, $note): void {
            $row = DB::table('refusals as f')->join('visitors as v', 'v.id', '=', 'f.visitor_id')->where('f.id', $refusal)->lockForUpdate()->first(['f.id', 'f.visitor_id', 'v.event_id', 'v.status']) ?? throw new DeskRefused('litige_inconnu', 'Cas introuvable.', 404);
            DB::table('refusals')->where('id', $row->id)->update(['decision' => $data['decision'], 'supervisor_decision' => $note !== '' ? $note : null, 'supervisor_id' => $r->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
            if ($data['decision'] === 'autorise' && $row->status === 'refuse') {
                // La personne repasse « attendue » avec la mention de l'autorisation : l'agent peut la traiter à l'accueil.
                DB::table('visitors')->where('id', $row->visitor_id)->update(['status' => 'attendu', 'access_note' => $note, 'updated_at' => now()]);
            }
            if ($data['decision'] !== 'a_revoir') {
                $this->alerts->resolve('visiteur', $row->visitor_id, 'refus');
            }
            $this->audit->append('litige.decide', $r->user()->id, ['refusal_id' => $row->id, 'visitor_id' => $row->visitor_id, 'decision' => $data['decision'], 'event_id' => $row->event_id]);
        });

        return back();
    }

    public function groupes(Request $r): Response
    {
        $event = $this->event($r);

        return Inertia::render('Pilotage/Groupes', [...$this->shared($event), 'groups' => fn () => $event ? $this->pilotage->groups($event->id) : []]);
    }

    public function evacuation(Request $r): Response
    {
        return Inertia::render('Pilotage/Evacuation', [...$this->shared($this->event($r))]);
    }

    /** Données de la liste d'évacuation. Chaque consultation est journalisée (identifiants et nombres seulement). */
    public function evacuationData(Request $r): JsonResponse
    {
        $event = $this->event($r) ?? throw new DeskRefused('evenement_inconnu', 'Aucun événement.', 404);
        $data = $this->pilotage->evacuation($event);
        $this->audit->append('evacuation.consultee', $r->user()->id, ['event_id' => $event->id, 'presents' => $data['total'], 'zones' => count($data['zones'])]);

        return response()->json($data);
    }
}
