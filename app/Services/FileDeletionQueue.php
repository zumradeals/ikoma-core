<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Les références sont détachées dans la transaction ; les fichiers sont supprimés après validation. */
final class FileDeletionQueue
{
    public function enqueue(string $path): void
    {
        if (preg_match('~^(photos|identity-documents|profile-portraits)/[0-9a-f-]{36}\.enc$~', $path) !== 1) {
            throw new \RuntimeException('Référence privée non conforme : nettoyage arrêté.');
        }
        DB::table('pending_file_deletions')->insertOrIgnore(['path' => $path, 'updated_at' => now()]);
    }

    public function drain(): int
    {
        $remaining = 0;
        foreach (DB::table('pending_file_deletions')->whereNull('completed_at')->orderBy('id')->cursor() as $file) {
            try {
                DB::table('pending_file_deletions')->where('id', $file->id)->increment('attempts');
                if (preg_match('~^(photos|identity-documents|profile-portraits)/[0-9a-f-]{36}\.enc$~', $file->path) !== 1) {
                    throw new \RuntimeException('Référence non conforme.');
                }
                if (Storage::disk('local')->exists($file->path) && ! Storage::disk('local')->delete($file->path)) {
                    throw new \RuntimeException('Fichier inaccessible.');
                }
                DB::table('pending_file_deletions')->where('id', $file->id)->update(['completed_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
                $remaining++;
            }
        }

        return $remaining;
    }
}
