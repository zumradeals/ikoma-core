<?php

namespace App\Services\Atelier;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Traçabilité des tirages de l'Atelier (états de TRAÇABILITÉ, jamais des états métier) :
 *   document généré → impression demandée → impression confirmée par l'opérateur.
 * Le navigateur ne confirme pas l'impression physique : « confirmée » est une déclaration humaine tracée, rien de plus.
 * Ce service n'écrit que dans badge_print_runs et badge_print_events (ajout seul) et dans le journal ; jamais dans les badges ni les associations.
 */
final class PrintTrail
{
    /**
     * @param  array{nature: string, event_id: ?string, batch_id: ?string, format: string, side: string, design: ?string, mode: ?string, planned_count: int, first_number: ?int, last_number: ?int, options: array<string, mixed>}  $ctx
     * @return string identifiant du tirage
     */
    public function generate(User $actor, array $ctx, ?string $reason): string
    {
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($actor, $ctx, $reason): string {
            $officiel = $ctx['nature'] === 'officiel';
            if ($officiel && $ctx['batch_id'] !== null) {
                // Deux générations simultanées d'un même lot ne peuvent pas contourner l'obligation de motif.
                DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('badge-print:'||?, 5))", [$ctx['batch_id']]);
            }
            if ($officiel && ($ctx['mode'] ?? null) === 'nominatif' && $reason === null && $this->alreadyGenerated($ctx['batch_id'], $ctx['first_number'], $ctx['last_number'])) {
                throw ValidationException::withMessages(['reason' => 'Ces badges nominatifs ont déjà fait l’objet d’un tirage : indiquez le motif de cette nouvelle génération (200 caractères au plus).']);
            }
            $id = (string) Str::uuid();
            $now = now();
            DB::table('badge_print_runs')->insert([
                'id' => $id, 'kind' => 'visiteur', 'event_id' => $ctx['event_id'], 'batch_id' => $ctx['batch_id'], 'format' => $ctx['format'], 'side' => $ctx['side'],
                'design' => $ctx['design'], 'mode' => $ctx['mode'], 'nature' => $ctx['nature'], 'planned_count' => $ctx['planned_count'],
                'first_number' => $ctx['first_number'], 'last_number' => $ctx['last_number'], 'reason' => $reason,
                'options' => json_encode($ctx['options'], JSON_THROW_ON_ERROR), 'created_by' => $actor->id, 'created_at' => $now,
            ]);
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'run_id' => $id, 'state' => 'genere', 'actor_id' => $actor->id, 'created_at' => $now]);
            app(Audit::class)->append('atelier.document_genere', $actor->id, [
                'tirage' => $id, 'type' => 'visiteur', 'evenement' => $ctx['event_id'], 'lot' => $ctx['batch_id'], 'format' => $ctx['format'], 'face' => $ctx['side'],
                'modele' => $ctx['design'], 'mode' => $ctx['mode'], 'nature' => $ctx['nature'], 'nombre' => $ctx['planned_count'],
                'premier' => $ctx['first_number'], 'dernier' => $ctx['last_number'], 'motif_fourni' => $reason !== null,
            ]);

            return $id;
        });
    }

    public function requested(User $actor, string $runId): void
    {
        DB::transaction(function () use ($actor, $runId): void {
            $run = $this->run($runId);
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'run_id' => $runId, 'state' => 'demande', 'actor_id' => $actor->id, 'created_at' => now()]);
            app(Audit::class)->append('atelier.impression_demandee', $actor->id, ['tirage' => $runId, 'nature' => $run->nature]);
        });
    }

    public function confirmed(User $actor, string $runId, int $printed, int $spoiled, ?string $note): void
    {
        $note = $this->cleanNote($note);
        DB::transaction(function () use ($actor, $runId, $printed, $spoiled, $note): void {
            $run = $this->run($runId, true);
            if (! DB::table('badge_print_events')->where('run_id', $runId)->where('state', 'demande')->exists()) {
                throw ValidationException::withMessages(['printed' => 'Lancez d’abord l’impression : une impression non demandée ne se confirme pas.']);
            }
            if (DB::table('badge_print_events')->where('run_id', $runId)->where('state', 'confirme')->exists()) {
                throw ValidationException::withMessages(['printed' => 'Ce tirage est déjà confirmé.']);
            }
            if ($printed < 0 || $spoiled < 0 || $printed > $run->planned_count || $spoiled > $run->planned_count) {
                throw ValidationException::withMessages(['printed' => 'Les nombres doivent être compris entre 0 et '.$run->planned_count.' (nombre de badges du tirage).']);
            }
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'run_id' => $runId, 'state' => 'confirme', 'actor_id' => $actor->id, 'printed' => $printed, 'spoiled' => $spoiled, 'note' => $note, 'created_at' => now()]);
            app(Audit::class)->append('atelier.impression_confirmee', $actor->id, ['tirage' => $runId, 'nature' => $run->nature, 'imprimes' => $printed, 'gaches' => $spoiled]);
        });
    }

    /** État de traçabilité courant : confirme > demande > genere. */
    public function state(string $runId): string
    {
        $states = DB::table('badge_print_events')->where('run_id', $runId)->pluck('state')->all();

        return in_array('confirme', $states, true) ? 'confirme' : (in_array('demande', $states, true) ? 'demande' : 'genere');
    }

    /**
     * Derniers tirages (affichage de l'Atelier). Aucune donnée personnelle ; le motif est réservé aux administrateurs des badges.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?string $eventId = null, int $limit = 30): array
    {
        $rows = DB::table('badge_print_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')->leftJoin('badge_batches as b', 'b.id', '=', 'r.batch_id')->leftJoin('events as e', 'e.id', '=', 'r.event_id')
            ->when($eventId !== null, fn ($q) => $q->where('r.event_id', $eventId))
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.event_id', 'r.format', 'r.side', 'r.design', 'r.mode', 'r.nature', 'r.planned_count', 'r.first_number', 'r.last_number', 'r.reason', 'r.created_at', 'u.name as by', 'b.name as batch', 'e.name as event']);
        $events = DB::table('badge_print_events')->whereIn('run_id', $rows->pluck('id'))->orderBy('created_at')->get(['run_id', 'state', 'printed', 'spoiled', 'note', 'created_at'])->groupBy('run_id');

        return $rows->map(function ($run) use ($events): array {
            $own = $events->get($run->id, collect());
            $confirm = $own->firstWhere('state', 'confirme');

            return [
                'id' => $run->id, 'event' => $run->event, 'batch' => $run->batch, 'format' => $run->format, 'side' => $run->side, 'design' => $run->design, 'mode' => $run->mode, 'nature' => $run->nature,
                'count' => $run->planned_count, 'first' => $run->first_number, 'last' => $run->last_number, 'reason' => $run->reason, 'created_at' => $run->created_at, 'by' => $run->by,
                'state' => $confirm ? 'confirme' : ($own->contains('state', 'demande') ? 'demande' : 'genere'),
                'requests' => $own->where('state', 'demande')->count(), 'printed' => $confirm?->printed, 'spoiled' => $confirm?->spoiled, 'note' => $confirm?->note,
            ];
        })->all();
    }

    public function find(string $runId): object
    {
        return $this->run($runId);
    }

    private function run(string $runId, bool $lock = false): object
    {
        abort_unless(Str::isUuid($runId), 404);
        $query = DB::table('badge_print_runs')->where('id', $runId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    /** Un support « nominatif » est regénéré dès qu'un tirage officiel antérieur du même lot recouvre ses numéros, confirmé ou non (DC-3). */
    private function alreadyGenerated(?string $batchId, ?int $first, ?int $last): bool
    {
        if ($batchId === null || $first === null || $last === null) {
            return false;
        }

        return DB::table('badge_print_runs')->where('batch_id', $batchId)->where('nature', 'officiel')->whereNotNull('first_number')
            ->where('first_number', '<=', $last)->where('last_number', '>=', $first)->exists();
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        if ($reason === null || $reason === '') {
            return null;
        }
        if (mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Motif invalide (200 caractères au plus, sans caractère spécial).']);
        }

        return $reason;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);
        if ($note === null || $note === '') {
            return null;
        }
        if (mb_strlen($note) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $note) === 1) {
            throw ValidationException::withMessages(['note' => 'Note invalide (200 caractères au plus, sans caractère spécial).']);
        }

        return $note;
    }
}
