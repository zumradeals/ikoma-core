<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class LoginAttempts
{
    public function key(Request $r): string
    {
        $identity = $r->is('two-factor-challenge') ? (string) $r->session()->get('login.id') : strtolower(trim((string) $r->input('email')));

        return 'login-progress:'.hash('sha256', $identity.'|'.$r->ip());
    }

    public function failed(Request $r): void
    {
        $key = $this->key($r);
        Cache::add($key.':count', 0, 3600);
        $count = (int) Cache::increment($key.':count');
        if ($count % 5 === 0) {
            Cache::put($key.':until', now()->timestamp + match (true) {
                $count >= 15 => 900, $count >= 10 => 300, default => 60
            }, 3600);
        }
    }

    public function remaining(Request $r): int
    {
        return max(0, (int) Cache::get($this->key($r).':until', 0) - now()->timestamp);
    }

    public function clear(Request $r, ?string $email = null): void
    {
        $keys = [$this->key($r)];
        if ($email !== null) {
            $keys[] = 'login-progress:'.hash('sha256', strtolower(trim($email)).'|'.$r->ip());
        }
        foreach (array_unique($keys) as $key) {
            Cache::forget($key.':count');
            Cache::forget($key.':until');
        }
    }

    /** @return array{ip: ?string, appareil_hash:string} */
    public function context(Request $r): array
    {
        return ['ip' => $r->ip(), 'appareil_hash' => hash('sha256', (string) ($r->header('X-Device-Token') ?: $r->userAgent()))];
    }
}
