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
                'id' => $id, 'kind' => 'visiteur', 'event_id' => $ctx['event_id'], 'batch_id' => $ctx['batch_id'], 'operator_id' => $actor->id, 'nature' => $ctx['nature'],
                'model' => $ctx['design'], 'mode' => $ctx['mode'], 'format' => $ctx['format'], 'face' => $ctx['side'], 'badge_count' => $ctx['planned_count'],
                'first_code' => $ctx['first_number'] === null ? null : self::code($ctx['first_number']), 'last_code' => $ctx['last_number'] === null ? null : self::code($ctx['last_number']),
                'first_number' => $ctx['first_number'], 'last_number' => $ctx['last_number'], 'reason' => $reason,
                'options' => json_encode($ctx['options'], JSON_THROW_ON_ERROR), 'generated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'print_run_id' => $id, 'operator_id' => $actor->id, 'event_type' => 'generated', 'created_at' => $now]);
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
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'print_run_id' => $runId, 'operator_id' => $actor->id, 'event_type' => 'requested', 'created_at' => now()]);
            app(Audit::class)->append('atelier.impression_demandee', $actor->id, ['tirage' => $runId, 'nature' => $run->nature]);
        });
    }

    public function confirmed(User $actor, string $runId, int $printed, int $spoiled, ?string $note): void
    {
        $note = $this->cleanNote($note);
        DB::transaction(function () use ($actor, $runId, $printed, $spoiled, $note): void {
            $run = $this->run($runId, true);
            if (! DB::table('badge_print_events')->where('print_run_id', $runId)->where('event_type', 'requested')->exists()) {
                throw ValidationException::withMessages(['printed' => 'Lancez d’abord l’impression : une impression non demandée ne se confirme pas.']);
            }
            if (DB::table('badge_print_events')->where('print_run_id', $runId)->where('event_type', 'operator_confirmed')->exists()) {
                throw ValidationException::withMessages(['printed' => 'Ce tirage est déjà confirmé.']);
            }
            if ($printed < 0 || $spoiled < 0 || $printed > $run->badge_count || $spoiled > $run->badge_count) {
                throw ValidationException::withMessages(['printed' => 'Les nombres doivent être compris entre 0 et '.$run->badge_count.' (nombre de badges du tirage).']);
            }
            DB::table('badge_print_events')->insert(['id' => (string) Str::uuid(), 'print_run_id' => $runId, 'operator_id' => $actor->id, 'event_type' => 'operator_confirmed', 'printed' => $printed, 'spoiled' => $spoiled, 'note' => $note, 'created_at' => now()]);
            app(Audit::class)->append('atelier.impression_confirmee', $actor->id, ['tirage' => $runId, 'nature' => $run->nature, 'imprimes' => $printed, 'gaches' => $spoiled]);
        });
    }

    /** État de traçabilité courant : operator_confirmed > requested > generated. */
    public function state(string $runId): string
    {
        $states = DB::table('badge_print_events')->where('print_run_id', $runId)->pluck('event_type')->all();

        return in_array('operator_confirmed', $states, true) ? 'operator_confirmed' : (in_array('requested', $states, true) ? 'requested' : 'generated');
    }

    /** Code d'affichage d'un badge d'après son numéro (format historique B-nnn, jamais modifié). */
    private static function code(int $number): string
    {
        return 'B-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Derniers tirages (affichage de l'Atelier). Aucune donnée personnelle ; le motif est réservé aux administrateurs des badges.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?string $eventId = null, int $limit = 30): array
    {
        $rows = DB::table('badge_print_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.operator_id')->leftJoin('badge_batches as b', 'b.id', '=', 'r.batch_id')->leftJoin('events as e', 'e.id', '=', 'r.event_id')
            ->when($eventId !== null, fn ($q) => $q->where('r.event_id', $eventId))
            ->orderByDesc('r.generated_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.event_id', 'r.format', 'r.face', 'r.model', 'r.mode', 'r.nature', 'r.badge_count', 'r.first_number', 'r.last_number', 'r.reason', 'r.generated_at', 'u.name as by', 'b.name as batch', 'e.name as event']);
        $events = DB::table('badge_print_events')->whereIn('print_run_id', $rows->pluck('id'))->orderBy('created_at')->get(['print_run_id', 'event_type', 'printed', 'spoiled', 'note', 'created_at'])->groupBy('print_run_id');

        return $rows->map(function ($run) use ($events): array {
            $own = $events->get($run->id, collect());
            $confirm = $own->firstWhere('event_type', 'operator_confirmed');

            return [
                'id' => $run->id, 'event' => $run->event, 'batch' => $run->batch, 'format' => $run->format, 'side' => $run->face, 'design' => $run->model, 'mode' => $run->mode, 'nature' => $run->nature,
                'count' => $run->badge_count, 'first' => $run->first_number, 'last' => $run->last_number, 'reason' => $run->reason, 'created_at' => $run->generated_at, 'by' => $run->by,
                'state' => $confirm ? 'operator_confirmed' : ($own->contains('event_type', 'requested') ? 'requested' : 'generated'),
                'requests' => $own->where('event_type', 'requested')->count(), 'printed' => $confirm?->printed, 'spoiled' => $confirm?->spoiled, 'note' => $confirm?->note,
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
        if ($lock) {
            // Le compte applicatif n'a pas le droit UPDATE sur le registre (ajout seul) : aucun verrou de ligne possible, donc verrou consultatif par tirage.
            DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('badge-print-run:'||?, 6))", [$runId]);
        }

        return DB::table('badge_print_runs')->where('id', $runId)->first() ?? abort(404);
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
