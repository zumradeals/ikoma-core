<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\AuditChain;
use App\Services\AuditSeal;
use App\Services\DataRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeAudit extends Command
{
    protected $signature = 'audit:purger {--key= : Clé privée hors serveur web} {--public-key= : Clé publique de confiance} {--recu= : Nouveau fichier privé hors serveur web} {--confirmer}';

    protected $description = 'Supprime un préfixe échu du journal après vérification et production d’un point de reprise signé';

    public function handle(DataRetention $retention, AuditChain $chain, AuditSeal $seal): int
    {
        try {
            $retention->requirePrivileged();
            if (! $this->option('confirmer') || DB::table('settings')->where('key', 'conservation_validee')->value('value') !== '1' || ! $this->option('recu') || ! $this->option('public-key')) {
                throw new \RuntimeException('Confirmation, politique approuvée, reçu extérieur et clés requis.');
            }
            DB::transaction(function () use ($retention, $chain, $seal): void {
                DB::statement('SELECT pg_advisory_xact_lock(731901)');
                $chain->verify((string) $this->option('public-key'));
                $cutoff = now()->subDays($retention->settings()['audit'])->utc()->format('Y-m-d\TH:i:s.u\Z');
                // Un préfixe contigu : aucune suppression au milieu de la chaîne, même avec des heures historiques désordonnées.
                $firstKept = DB::table('audit_logs')->where('occurred_at', '>=', $cutoff)->min('id');
                $query = DB::table('audit_logs')->where('occurred_at', '<', $cutoff);
                if ($firstKept !== null) {
                    $query->where('id', '<', $firstKept);
                }
                $last = (clone $query)->orderByDesc('id')->first();
                if ($last === null) {
                    $this->info('Aucune ligne échue.');

                    return;
                }
                $count = (clone $query)->count();
                $root = $seal->root((string) $this->option('public-key'));
                $signed = $seal->sign(['version' => 1, 'purpose' => 'purge', 'id' => (int) $last->id, 'hash' => $last->hash, 'occurred_at' => $last->occurred_at, 'issued_at' => now()->utc()->toIso8601String(), 'lignes' => $count, 'previous_checkpoint' => $root], (string) $this->option('key'));
                $seal->verify($signed, (string) $this->option('public-key'));
                // Création exclusive, permission 0600 ; un reçu antérieur n'est jamais écrasé.
                $path = (string) $this->option('recu');
                $realParent = realpath(dirname($path));
                if ($realParent === false || str_starts_with($realParent.'/', public_path().'/')) {
                    throw new \RuntimeException('Emplacement de reçu privé requis.');
                }
                $oldMask = umask(0077);
                try {
                    $file = @fopen($path, 'x');
                } finally {
                    umask($oldMask);
                }
                if ($file === false) {
                    throw new \RuntimeException('Impossible de créer le reçu.');
                }
                $encoded = json_encode($signed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                try {
                    if (fwrite($file, $encoded) !== strlen($encoded) || ! fflush($file) || ! fsync($file)) {
                        throw new \RuntimeException('Reçu incomplet.');
                    }
                } finally {
                    fclose($file);
                }
                // Cette ligne reste dans la chaîne ; aucun nom, aucune valeur libre.
                app(Audit::class)->append('journal.purge', null, ['jusqua_id' => (int) $last->id, 'lignes' => $count, 'hash' => $last->hash]);
                DB::table('settings')->updateOrInsert(['key' => 'audit_checkpoint'], ['value' => $encoded]);
                $query->delete();
                $chain->verify((string) $this->option('public-key'));
                $this->info($count.' lignes échues supprimées ; reçu signé à conserver hors serveur.');
            });
        } catch (\Throwable) {
            $this->error('Purge du journal refusée : vérifier les droits, la politique, la chaîne et les fichiers de clés/reçu. Un reçu produit avant un échec doit être conservé et rapproché de la base.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
