<?php

namespace App\Services;

use App\Enums\BadgeStatus;
use App\Models\BadgeBatch;
use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fabrication des lots de badges (codes continus, jetons QR) et réservation de plages à un poste ou à un groupe. */
final class BadgeLots
{
    public const MAX_EXPECTED = 5000;

    public function __construct(private readonly Audit $audit) {}

    /** Quantité fabriquée = nombre attendu + marge (pourcentage, arrondi au supérieur). */
    public static function manufactured(int $expected, int $marginPercent): int
    {
        return $expected + intdiv($expected * $marginPercent + 99, 100);
    }

    public function generate(string $eventId, string $categoryId, int $expected, User $actor): BadgeBatch
    {
        if ($expected < 1 || $expected > self::MAX_EXPECTED) {
            throw new BadgeRefused('quantite', 'Le nombre attendu doit être compris entre 1 et '.self::MAX_EXPECTED.'.');
        }

        return DB::transaction(function () use ($eventId, $categoryId, $expected, $actor): BadgeBatch {
            EventAvailability::requireOpen($eventId);
            $category = DB::table('categories')->where('id', $categoryId)->where('event_id', $eventId)->first(['name']) ?? throw new BadgeRefused('categorie', 'Catégorie inconnue pour cet événement.');
            // Verrou par événement : deux générations simultanées ne peuvent pas produire le même code.
            DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('badge-codes:'||?, 4))", [$eventId]);
            $percent = (int) DB::table('settings')->where('key', 'badge_marge_pourcent')->value('value');
            $total = self::manufactured($expected, $percent);
            $number = (int) DB::table('badges')->where('event_id', $eventId)->whereRaw("code ~ '^B-[0-9]+\$'")->selectRaw('max(substring(code from 3)::integer) as n')->value('n');
            $retired = (int) DB::table('retired_badge_identifiers')->where('event_id', $eventId)->whereRaw("code ~ '^B-[0-9]+$'")->selectRaw('max(substring(code from 3)::integer) as n')->value('n');
            $number = max($number, $retired);
            $rank = DB::table('badge_batches')->where('event_id', $eventId)->where('category_id', $categoryId)->count() + 1;
            $batch = BadgeBatch::create(['event_id' => $eventId, 'category_id' => $categoryId, 'name' => "Lot {$category->name} n°{$rank}", 'quantity' => $total, 'margin' => $total - $expected]);
            $now = now();
            $rows = [];
            for ($i = 1; $i <= $total; $i++) {
                $rows[] = ['id' => (string) Str::uuid7(), 'event_id' => $eventId, 'category_id' => $categoryId, 'badge_batch_id' => $batch->id, 'code' => 'B-'.str_pad((string) ($number + $i), 3, '0', STR_PAD_LEFT), 'qr_token' => bin2hex(random_bytes(32)), 'status' => BadgeStatus::Available->value, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('badges')->insert($chunk);
            }
            $this->audit->append('badge.lot_cree', $actor->id, ['batch_id' => $batch->id, 'event_id' => $eventId, 'category_id' => $categoryId, 'attendus' => $expected, 'marge' => $total - $expected, 'fabriques' => $total, 'premier' => $number + 1, 'dernier' => $number + $total]);

            return $batch;
        });
    }

    /**
     * Réserve d'un coup les badges encore disponibles et non réservés d'un lot
     * pour un poste précis. Le choix du poste est explicite dans l'administration.
     * Les badges d'un groupe ou d'un autre poste restent intouchables.
     */
    public function reserveAvailable(string $batchId, Device $target, User $actor): int
    {
        return DB::transaction(function () use ($batchId, $target, $actor): int {
            $batch = BadgeBatch::query()->lockForUpdate()->find($batchId)
                ?? throw new BadgeRefused('lot', 'Lot de badges inconnu.');
            EventAvailability::requireOpen($batch->event_id);
            if ($target->event_id !== $batch->event_id || $target->revoked_at !== null) {
                throw new BadgeRefused('autre_evenement', 'Le poste est inactif ou appartient à un autre événement.');
            }
            $ids = DB::table('badges')->where('badge_batch_id', $batch->id)
                ->where('status', BadgeStatus::Available->value)
                ->whereNull('reserved_device_id')->whereNull('reserved_group_id')
                ->orderBy('id')->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) {
                throw new BadgeRefused('aucun_badge_libre', 'Aucun badge disponible sans réservation dans ce lot.');
            }
            DB::table('badges')->whereIn('id', $ids)->update([
                'reserved_device_id' => $target->id, 'updated_at' => now(),
            ]);
            $this->audit->append('badge.reserve', $actor->id, [
                'batch_id' => $batch->id, 'event_id' => $batch->event_id,
                'nombre' => $ids->count(), 'cible' => 'poste', 'cible_id' => $target->id,
                'mode' => 'tous_disponibles',
            ]);

            return $ids->count();
        });
    }

    /**
     * Réserve les badges numérotés de $from à $to d’un lot à un poste ou à un groupe. Tout ou rien : un seul badge indisponible refuse la plage.
     * Retourne le nombre de badges réservés.
     */
    public function reserve(string $batchId, int $from, int $to, Device|Group $target, User $actor): int
    {
        if ($from < 1 || $to < $from) {
            throw new BadgeRefused('plage', 'Plage invalide : le premier numéro doit être au moins 1 et ne pas dépasser le dernier.');
        }

        return DB::transaction(function () use ($batchId, $from, $to, $target, $actor): int {
            $batch = BadgeBatch::query()->lockForUpdate()->find($batchId) ?? throw new BadgeRefused('lot', 'Lot inconnu.');
            EventAvailability::requireOpen($batch->event_id);
            if ($target->event_id !== $batch->event_id) {
                throw new BadgeRefused('autre_evenement', 'Le poste ou le groupe appartient à un autre événement que le lot.');
            }
            $rows = DB::table('badges')->where('badge_batch_id', $batch->id)
                ->whereRaw("(CASE WHEN code ~ '^B-[0-9]+\$' THEN substring(code from 3)::integer END) BETWEEN ? AND ?", [$from, $to])
                ->orderBy('code')->lockForUpdate()->get(['id', 'status', 'reserved_device_id', 'reserved_group_id']);
            $expected = $to - $from + 1;
            if ($rows->count() !== $expected) {
                throw new BadgeRefused('plage', "Plage invalide : {$rows->count()} badge(s) trouvé(s) dans ce lot pour {$expected} demandé(s).");
            }
            $blocked = $rows->filter(fn ($r) => $r->status !== BadgeStatus::Available->value || $r->reserved_device_id !== null || $r->reserved_group_id !== null)->count();
            if ($blocked > 0) {
                throw new BadgeRefused('indisponible', "{$blocked} badge(s) de cette plage ne sont pas disponibles ou sont déjà réservés. Aucune réservation n’a été faite.");
            }
            $column = $target instanceof Device ? 'reserved_device_id' : 'reserved_group_id';
            DB::table('badges')->whereIn('id', $rows->pluck('id'))->update([$column => $target->id, 'updated_at' => now()]);
            $this->audit->append('badge.reserve', $actor->id, ['batch_id' => $batch->id, 'event_id' => $batch->event_id, 'du' => $from, 'au' => $to, 'nombre' => $expected, 'cible' => $target instanceof Device ? 'poste' : 'groupe', 'cible_id' => $target->id]);

            return $expected;
        });
    }
}
