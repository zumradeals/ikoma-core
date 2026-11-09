<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Passerelle d'écriture du registre des émissions (`badge_issuances`, ajout seul).
 *
 * Elle n'a aucune règle métier : les règles d'éligibilité, de cycle de vie et d'idempotence sont portées par BadgeIssuance ; les garanties d'unicité par les contraintes de la base.
 * Existe séparément pour que HolderCards (cartes permanentes) enregistre ses émissions dans le même registre sans dépendre de BadgeIssuance.
 */
final class IssuanceLedger
{
    /** @param array<string, mixed> $row */
    public static function record(array $row): string
    {
        $id = (string) Str::uuid();
        DB::table('badge_issuances')->insert([
            'id' => $id, 'operation_id' => $row['operation_id'], 'purpose' => $row['purpose'], 'support_type' => $row['support_type'], 'person_id' => $row['person_id'],
            'visitor_id' => $row['visitor_id'] ?? null, 'holder_id' => $row['holder_id'] ?? null, 'event_id' => $row['event_id'], 'badge_id' => $row['badge_id'],
            'assignment_id' => $row['assignment_id'] ?? null, 'replaces_badge_id' => $row['replaces_badge_id'] ?? null, 'actor_id' => $row['actor_id'] ?? null, 'created_at' => now(),
        ]);

        return $id;
    }

    public static function find(string $operationId): ?object
    {
        return DB::table('badge_issuances as i')->join('badges as b', 'b.id', '=', 'i.badge_id')->where('i.operation_id', $operationId)
            ->first(['i.id', 'i.operation_id', 'i.purpose', 'i.support_type', 'i.person_id', 'i.visitor_id', 'i.holder_id', 'i.event_id', 'i.badge_id', 'i.assignment_id', 'b.code']);
    }
}
