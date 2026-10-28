<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Signature asymétrique : la clé privée reste sur la console privilégiée, jamais dans l'application web. */
final class AuditSeal
{
    /** @param array<string, mixed> $payload
     * @return array{payload:array<string,mixed>,signature:string}
     */
    public function sign(array $payload, string $keyPath): array
    {
        $key = openssl_pkey_get_private((string) @file_get_contents($keyPath));
        if ($key === false || (openssl_pkey_get_details($key)['bits'] ?? 0) < 2048 || ! openssl_sign($this->canonical($payload), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Clé de signature absente ou invalide.');
        }

        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    }

    /** @param array<string, mixed> $envelope
     * @return array<string,mixed>
     */
    public function verify(array $envelope, ?string $publicKey = null): array
    {
        $payload = $envelope['payload'] ?? null;
        $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);
        $key = openssl_pkey_get_public((string) @file_get_contents($publicKey ?? base_path('.secrets/audit-public.pem')));
        if (! is_array($payload) || $signature === false || $key === false || openssl_verify($this->canonical($payload), $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('Signature ou clé publique non valide.');
        }

        return $payload;
    }

    /** @return array{id:int,hash:string} */
    public function root(?string $publicKey = null): array
    {
        $stored = DB::table('settings')->where('key', 'audit_checkpoint')->value('value');
        if ($stored === null) {
            return ['id' => 0, 'hash' => str_repeat('0', 64)];
        }
        $payload = $this->verify(json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR), $publicKey);
        if (($payload['purpose'] ?? '') !== 'purge' || ! is_int($payload['id'] ?? null) || $payload['id'] < 1 || preg_match('/^[0-9a-f]{64}$/', (string) ($payload['hash'] ?? '')) !== 1) {
            throw new \RuntimeException('Point de reprise invalide.');
        }

        return ['id' => $payload['id'], 'hash' => $payload['hash']];
    }

    /** @param array<string, mixed> $payload */
    public function canonical(array $payload): string
    {
        // L'ordre des propriétés est fixé par le producteur et conservé dans le document signé.
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
