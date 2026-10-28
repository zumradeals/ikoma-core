<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Liste d'interdits (audit global A2). Persistante entre les événements ; seul l'officier de sécurité l'alimente. L'agent d'accueil voit seulement
 * « personne signalée : appelez le superviseur », jamais le motif. Une correspondance de nom peut être un homonyme : le superviseur tranche.
 * Dans le profil renforcé, lever une interdiction demande la validation d'un administrateur (double validation, D2).
 * Le journal et les alertes ne contiennent jamais un nom : un numéro d'entrée, un nombre de personnes.
 */
final class Watchlist
{
    public function __construct(private readonly Audit $audit, private readonly Alerts $alerts) {}

    /** Condition SQL : le visiteur (alias donné) correspond à une entrée active. */
    public static function matchSql(string $alias = 'v'): string
    {
        return "EXISTS (SELECT 1 FROM watchlist_entries w WHERE w.lifted_at IS NULL AND w.name_key = public.ikoma_name_key({$alias}.full_name))";
    }

    public function isFlagged(string $visitorId): bool
    {
        return DB::table('visitors as v')->where('v.id', $visitorId)->whereRaw(self::matchSql())->exists();
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        return DB::table('watchlist_entries as w')->join('users as u', 'u.id', '=', 'w.created_by')->whereNull('w.lifted_at')->orderBy('w.full_name')
            ->get(['w.id', 'w.full_name', 'w.reason', 'w.created_at', 'u.name as added_by'])->map(fn ($r) => (array) $r)->all();
    }

    public function add(string $fullName, string $reason, User $officer): string
    {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
        $key = (string) DB::selectOne('SELECT public.ikoma_name_key(?) AS k', [$fullName])->k;
        if ($key === '') {
            throw ValidationException::withMessages(['full_name' => 'Indiquez un nom.']);
        }
        if (DB::table('watchlist_entries')->where('name_key', $key)->whereNull('lifted_at')->exists()) {
            throw ValidationException::withMessages(['full_name' => 'Cette personne figure déjà sur la liste.']);
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $fullName, $key, $reason, $officer): void {
            DB::table('watchlist_entries')->insert(['id' => $id, 'full_name' => $fullName, 'name_key' => $key, 'reason' => $reason, 'created_by' => $officer->id]);
            $this->audit->append('interdit.ajoute', $officer->id, ['entry_id' => $id]);
            $this->scanEvents($id);
        });

        return $id;
    }

    /** Lève une entrée (action finale : appelée directement en profil standard, ou après validation d'un administrateur). */
    public function lift(string $entryId, User $by, bool $doubleValidation = false): void
    {
        $count = DB::table('watchlist_entries')->where('id', $entryId)->whereNull('lifted_at')->update(['lifted_at' => now(), 'lifted_by' => $by->id]);
        if ($count > 0) {
            $this->audit->append('interdit.leve', $by->id, ['entry_id' => $entryId, 'double_validation' => $doubleValidation]);
            DB::table('events')->increment('list_revision'); // les tablettes retirent le signalement
        }
    }

    /** Demande de levée : double validation dans le profil renforcé, levée directe sinon. Renvoie vrai si la levée est faite. */
    public function requestLift(string $entryId, User $officer): bool
    {
        $entry = DB::table('watchlist_entries')->where('id', $entryId)->whereNull('lifted_at')->first();
        abort_if($entry === null, 404);
        if (! SecurityProfile::strict()) {
            $this->lift($entryId, $officer);

            return true;
        }
        app(Approvals::class)->request('interdit.lever', ['entry_id' => $entryId], 'Lever l’interdiction visant « '.$entry->full_name.' ».', $officer);

        return false;
    }

    /** Une nouvelle entrée : signale les invités déjà présents dans les événements (un seul message par événement, sans nom). */
    private function scanEvents(string $entryId): void
    {
        $rows = DB::select('SELECT v.event_id, count(*) AS n FROM visitors v JOIN watchlist_entries w ON w.id = ? AND w.name_key = public.ikoma_name_key(v.full_name)
            WHERE v.withdrawn_at IS NULL AND v.event_id IN (SELECT id FROM events WHERE ends_at IS NULL OR ends_at > now()) GROUP BY v.event_id', [$entryId]);
        foreach ($rows as $r) {
            $this->raiseForEvent($r->event_id, (int) $r->n, 'entry:'.$entryId);
        }
        if ($rows !== []) {
            DB::table('events')->increment('list_revision');
        }
    }

    /** Après un import ou l'ajout par un hôte : signale les invités ajoutés qui correspondent à la liste. */
    public function checkNew(string $eventId, ?string $importBatchId = null, ?string $visitorId = null): int
    {
        $q = DB::table('visitors as v')->where('v.event_id', $eventId)->whereNull('v.withdrawn_at')->whereRaw(self::matchSql());
        $importBatchId !== null ? $q->where('v.import_batch_id', $importBatchId) : null;
        $visitorId !== null ? $q->where('v.id', $visitorId) : null;
        $n = $q->count();
        if ($n > 0) {
            $this->raiseForEvent($eventId, $n, 'new:'.($importBatchId ?? $visitorId));
        }

        return $n;
    }

    /** Quelqu'un de signalé est devant un poste ou un point de contrôle : le superviseur le sait aussitôt. */
    public function raiseEncounter(string $eventId, string $visitorId, string $where): void
    {
        $this->alerts->raise($eventId, 'personne_signalee', 'critique', 'visiteur', $visitorId, 'Personne signalée par l’officier de sécurité '.$where.'. Ne la laissez pas passer sans l’avis du superviseur.', 'signalee:'.$visitorId.':'.$where);
    }

    private function raiseForEvent(string $eventId, int $count, string $key): void
    {
        $this->alerts->raise($eventId, 'personne_signalee', 'haute', null, null, $count.' personne'.($count > 1 ? 's' : '').' de la liste de l’événement '.($count > 1 ? 'correspondent' : 'correspond').' à la liste d’interdits de l’officier de sécurité.', 'signalee-liste:'.$key);
    }
}
