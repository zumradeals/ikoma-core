<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approbation des invités par l'officier de sécurité (audit global A3). Le journal ne contient que des nombres et des identifiants de lot : jamais un nom.
 * L'officier voit les noms à l'écran pour décider, comme les autres rôles de consultation.
 */
final class VisitorApprovals
{
    public const PENDING = 'a_approuver';

    public function __construct(private readonly Audit $audit) {}

    /** Statut d'un invité créé maintenant : en attente dans le profil renforcé, approuvé sinon. */
    public static function initialStatus(): string
    {
        return SecurityProfile::strict() ? self::PENDING : 'approuve';
    }

    public function pendingCount(?string $eventId = null): int
    {
        return DB::table('visitors')->where('approval_status', self::PENDING)->whereNull('withdrawn_at')->when($eventId, fn ($q) => $q->where('event_id', $eventId))->count();
    }

    /**
     * Lots en attente : un lot par import de liste, et un lot « ajouts par les hôtes ».
     *
     * @return list<array{key:string,label:string,count:int,created_at:?string}>
     */
    public function batches(string $eventId): array
    {
        $rows = DB::select("SELECT v.import_batch_id AS batch, count(*) AS n, min(v.created_at) AS first_at, max(b.file_reference) AS file
            FROM visitors v LEFT JOIN import_batches b ON b.id = v.import_batch_id
            WHERE v.event_id = ? AND v.approval_status = 'a_approuver' AND v.withdrawn_at IS NULL GROUP BY v.import_batch_id ORDER BY min(v.created_at)", [$eventId]);

        return array_map(fn ($r) => ['key' => $r->batch ?? 'hotes', 'label' => $r->batch === null ? 'Invités ajoutés par les hôtes' : 'Liste importée'.($r->file ? ' : '.$r->file : ''), 'count' => (int) $r->n, 'created_at' => $r->first_at], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function visitors(string $eventId, string $batchKey, int $limit = 100): array
    {
        $q = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')
            ->where('v.event_id', $eventId)->where('v.approval_status', self::PENDING)->whereNull('v.withdrawn_at');
        $batchKey === 'hotes' ? $q->whereNull('v.import_batch_id') : $q->where('v.import_batch_id', $batchKey);

        return $q->orderBy('v.full_name')->limit($limit)->get(['v.id', 'v.full_name', 'v.organisation', 'c.name as category', 'h.name as host'])->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Décide pour un lot entier ou pour des invités précis. Renvoie le nombre d'invités concernés.
     *
     * @param  list<string>|null  $ids
     */
    public function decide(string $eventId, string $batchKey, ?array $ids, bool $approve, User $officer): int
    {
        return DB::transaction(function () use ($eventId, $batchKey, $ids, $approve, $officer): int {
            $q = DB::table('visitors')->where('event_id', $eventId)->where('approval_status', self::PENDING)->whereNull('withdrawn_at');
            $batchKey === 'hotes' ? $q->whereNull('import_batch_id') : $q->where('import_batch_id', $batchKey);
            if ($ids !== null) {
                $q->whereIn('id', $ids);
            }
            $count = $q->update(['approval_status' => $approve ? 'approuve' : 'refuse', 'approved_by' => $officer->id, 'approved_at' => now(), 'updated_at' => now()]);
            if ($count > 0) {
                DB::table('events')->where('id', $eventId)->increment('list_revision'); // les tablettes reçoivent la nouvelle situation
                $this->audit->append($approve ? 'liste.approuvee' : 'liste.refusee', $officer->id, ['event_id' => $eventId, 'lot' => $batchKey, 'nombre' => $count, 'tout_le_lot' => $ids === null]);
            }

            return $count;
        });
    }
}
