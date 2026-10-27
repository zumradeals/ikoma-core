<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Audit
{
    /** @param array<string, mixed> $details */
    public function append(string $action, ?string $actor = null, array $details = []): void
    {
        DB::transaction(function () use ($action, $actor, $details): void {
            DB::statement('SELECT pg_advisory_xact_lock(731901)');
            $previous = DB::table('audit_logs')->orderByDesc('id')->value('hash') ?? str_repeat('0', 64);
            $at = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $data = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = $this->digest($previous, $actor, $action, $at, $data);
            DB::table('audit_logs')->insert(['actor_id' => $actor, 'action' => $action, 'details' => $data, 'occurred_at' => $at, 'previous_hash' => $previous, 'hash' => $hash]);
        });
    }

    public function digest(string $previous, ?string $actor, string $action, string $at, string $details): string
    {
        return hash('sha256', json_encode([$previous, $actor, $action, $at, $details], JSON_THROW_ON_ERROR));
    }
}
