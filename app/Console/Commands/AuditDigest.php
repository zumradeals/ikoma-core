<?php

namespace App\Console\Commands;

use App\Services\AuditChain;
use App\Services\AuditSeal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AuditDigest extends Command
{
    protected $signature = 'audit:digest {--key= : Fichier privé RSA, hors serveur web} {--public-key= : Clé publique pour la reprise après purge}';

    protected $description = 'Produit sur la sortie standard le condensé signé à conserver hors serveur';

    public function handle(AuditChain $chain, AuditSeal $seal): int
    {
        try {
            $envelope = DB::transaction(function () use ($chain, $seal): array {
                DB::statement('SELECT pg_advisory_xact_lock(731901)');
                $tail = $chain->verify($this->option('public-key') ?: null);

                return $seal->sign(['version' => 1, 'purpose' => 'anchor', 'id' => $tail['id'], 'hash' => $tail['hash'], 'occurred_at' => $tail['occurred_at'], 'issued_at' => now()->utc()->toIso8601String()], (string) $this->option('key'));
            });
            $this->line(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            $this->error('Condensé non produit : vérifiez la chaîne et les fichiers de clés.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
