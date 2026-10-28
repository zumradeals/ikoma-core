<?php

namespace App\Console\Commands;

use App\Services\AuditChain;
use App\Services\AuditSeal;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class VerifyAudit extends Command
{
    protected $signature = 'audit:verify {--anchor= : Condensé signé conservé hors serveur} {--public-key= : Clé publique de confiance}';

    protected $description = 'Vérifie la chaîne et, facultativement, son ancrage extérieur';

    public function handle(AuditChain $chain, AuditSeal $seal): int
    {
        $start = microtime(true);
        try {
            DB::transaction(function () use ($chain, $seal): void {
                DB::statement('SELECT pg_advisory_xact_lock(731901)');
                $result = $chain->verify($this->option('public-key') ?: null);
                if ($this->option('anchor')) {
                    $anchor = $seal->verify(json_decode((string) file_get_contents($this->option('anchor')), true, 512, JSON_THROW_ON_ERROR), $this->option('public-key') ?: null);
                    $hash = DB::table('audit_logs')->where('id', (int) ($anchor['id'] ?? -1))->value('hash');
                    if ($hash === null || ! hash_equals((string) $hash, (string) ($anchor['hash'] ?? ''))) {
                        throw new \RuntimeException('Ancrage absent ou différent : vérifier également les archives hors serveur.');
                    }
                }
                $this->info('Chaîne du journal intacte. '.$result['lignes'].' lignes vérifiées.');
            });
        } catch (\Throwable $e) {
            // Les erreurs de base peuvent contenir des paramètres : seul un diagnostic produit localement est affiché.
            $this->error($e instanceof QueryException ? 'Vérification indisponible.' : $e->getMessage());

            return self::FAILURE;
        }
        $this->line('Durée : '.number_format(microtime(true) - $start, 3).' s.');

        return self::SUCCESS;
    }
}
