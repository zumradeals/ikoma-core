<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class AuditChain
{
    /** @return array{id:int,hash:string,occurred_at:?string,lignes:int} */
    public function verify(?string $publicKey = null): array
    {
        $root = app(AuditSeal::class)->root($publicKey);
        $previous = $root['hash'];
        $last = $root['id'];
        $at = null;
        $count = 0;
        foreach (DB::table('audit_logs')->orderBy('id')->lazyById(5000) as $row) {
            if ((int) $row->id <= $last || $row->previous_hash !== $previous || ! hash_equals($row->hash, app(Audit::class)->digest((int) $row->id, $previous, $row->actor_id, $row->action, $row->occurred_at, $row->details))) {
                throw new \RuntimeException('Rupture du journal à la ligne '.$row->id);
            }
            $previous = $row->hash;
            $last = (int) $row->id;
            $at = $row->occurred_at;
            $count++;
        }

        return ['id' => $last, 'hash' => $previous, 'occurred_at' => $at, 'lignes' => $count];
    }
}
