<?php

namespace App\Services\Imports;

use App\Services\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Le volume privé est partagé par PHP et le planificateur Docker. */
final class ListImport
{
    /** @return array<string,mixed> */
    public function preview(string $event, string $actor, UploadedFile $file, bool $createHosts): array
    {
        $format = app(TableReader::class)->check($file);

        return DB::transaction(function () use ($event, $actor, $file, $createHosts, $format) {
            $this->lock($event);
            $old = $this->read($event);
            if ($old && $old['expires_at'] > now()->timestamp && $old['actor_id'] !== $actor) {
                $this->fail('Un autre administrateur prépare déjà un import pour cet événement.');
            }
            // Le précédent aperçu reste intact tant que le nouveau fichier est refusé.
            $analysis = app(ListAnalysis::class)->analyse($file->getPathname(), $format, $event, $createHosts);
            $token = (string) Str::uuid();
            $directory = $this->directory($event);
            File::ensureDirectoryExists($directory, 0700, true);
            $path = $directory.'/'.$token.'.'.$format;
            if (! copy($file->getPathname(), $path)) {
                throw new \RuntimeException('Stockage privé indisponible.');
            }
            chmod($path, 0600);
            $preview = ['id' => $token, 'event_id' => $event, 'actor_id' => $actor, 'filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'path' => $path, 'format' => $format, 'create_hosts' => $createHosts, 'expires_at' => now()->timestamp + 3600, 'analysis' => $analysis];
            try {
                app(Audit::class)->append('liste.import_apercu', $actor, ['id' => $token, 'event_id' => $event, ...$analysis['summary']]);
                $this->write($event, $preview);
            } catch (\Throwable $e) {
                File::delete($path);
                throw $e;
            }
            if ($old) {
                app(Audit::class)->append('liste.import_abandonne', $old['actor_id'], ['id' => $old['id'], 'event_id' => $event, ...$old['analysis']['summary']]);
                File::delete($old['path']);
            }

            return $this->publicPreview($preview);
        });
    }

    /** @return array<string,mixed>|null */
    public function show(string $event, string $actor): ?array
    {
        return DB::transaction(function () use ($event, $actor) {
            $this->lock($event);
            $p = $this->read($event);
            if ($p) {
                abort_unless($p['actor_id'] === $actor, 403);
                if ($p['expires_at'] <= now()->timestamp) {
                    app(Audit::class)->append('liste.import_abandonne', $actor, ['id' => $p['id'], 'event_id' => $event, ...$p['analysis']['summary']]);
                    $this->delete($event, $p);

                    return null;
                }
            }

            return $p ? $this->publicPreview($p) : null;
        });
    }

    /** @return array<string,int|string> */
    public function confirm(string $event, string $actor, string $token): array
    {
        $result = DB::transaction(function () use ($event, $actor, $token) {
            $this->lock($event);
            // Rejouer la confirmation ne crée ni hôte ni visiteur supplémentaire.
            $done = DB::table('import_batches')->where('id', $token)->where('event_id', $event)->where('actor_id', $actor)->first();
            if ($done) {
                return ['id' => $done->id, ...json_decode($done->summary, true)];
            }
            $p = $this->owned($event, $actor);
            abort_unless($p && hash_equals($p['id'], $token), 409, 'Cet aperçu n’est plus disponible.');
            $analysis = app(ListAnalysis::class)->analyse($p['path'], $p['format'], $event, $p['create_hosts']);
            if ($analysis !== $p['analysis']) {
                $p['analysis'] = $analysis;
                $this->write($event, $p);
                $this->fail('La liste ou ses références ont changé. Relisez le rapport avant de confirmer.');
            }
            if (! $analysis['summary']['importable']) {
                $this->fail('Aucune ligne ne peut être importée. Corrigez le fichier.');
            }
            $at = now();
            DB::table('import_batches')->insert(['id' => $token, 'event_id' => $event, 'actor_id' => $actor, 'file_reference' => $p['filename'], 'status' => 'termine', 'summary' => json_encode($analysis['summary'], JSON_THROW_ON_ERROR), 'created_at' => $at, 'updated_at' => $at]);
            $hostIds = [];
            $visitors = [];
            foreach ($analysis['rows'] as $row) {
                $host = $row['host_id'];
                if (! $host && $row['host'] !== '') {
                    $key = DB::selectOne('SELECT public.ikoma_normalize(?) AS n', [$row['host']])->n;
                    if (! isset($hostIds[$key])) {
                        $hostIds[$key] = (string) Str::uuid();
                        DB::table('hosts')->insert(['id' => $hostIds[$key], 'event_id' => $event, 'name' => $row['host'], 'created_at' => $at, 'updated_at' => $at]);
                    }
                    $host = $hostIds[$key];
                }
                $visitors[] = ['id' => (string) Str::uuid(), 'event_id' => $event, 'full_name' => $row['full_name'], 'organisation' => $row['organisation'] ?: null, 'host_id' => $host, 'category_id' => $row['category_id'], 'import_batch_id' => $token, 'valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until'], 'source' => 'listé', 'status' => 'attendu', 'created_at' => $at, 'updated_at' => $at];
            }
            foreach (array_chunk($visitors, 200) as $chunk) {
                DB::table('visitors')->insert($chunk);
            }
            app(Audit::class)->append('liste.import_confirme', $actor, ['id' => $token, 'event_id' => $event, ...$analysis['summary']]);

            return ['id' => $token, ...$analysis['summary']];
        });
        // Après COMMIT seulement. En cas de crash ici, le rejeu/purge finit le nettoyage.
        DB::transaction(function () use ($event, $actor, $token) {
            $this->lock($event);
            $p = $this->read($event);
            if ($p && $p['actor_id'] === $actor && $p['id'] === $token) {
                $this->delete($event, $p);
            }
        });

        return $result;
    }

    public function abandon(string $event, string $actor, string $token): void
    {
        DB::transaction(function () use ($event, $actor, $token) {
            $this->lock($event);
            $p = $this->owned($event, $actor);
            if (! $p) {
                return;
            }
            abort_unless(hash_equals($p['id'], $token), 409);
            app(Audit::class)->append('liste.import_abandonne', $actor, ['id' => $p['id'], 'event_id' => $event, ...$p['analysis']['summary']]);
            $this->delete($event, $p);
        });
    }

    public function purge(): int
    {
        $count = 0;
        if (! File::isDirectory(Storage::disk('local')->path('imports'))) {
            return 0;
        }
        foreach (File::directories(Storage::disk('local')->path('imports')) as $dir) {
            $event = basename($dir);
            if (! Str::isUuid($event)) {
                continue;
            }
            DB::transaction(function () use ($event, &$count) {
                $this->lock($event);
                $p = $this->read($event);
                foreach (File::files($this->directory($event)) as $file) {
                    if ((! $p || $file->getPathname() !== $p['path']) && $file->getFilename() !== 'preview.enc' && $file->getMTime() < now()->timestamp - 3600) {
                        File::delete($file->getPathname());
                    }
                }
                if ($p && $p['expires_at'] <= now()->timestamp) {
                    if (! DB::table('import_batches')->where('id', $p['id'])->exists()) {
                        app(Audit::class)->append('liste.import_abandonne', $p['actor_id'], ['id' => $p['id'], 'event_id' => $event, ...$p['analysis']['summary']]);
                    }
                    $this->delete($event, $p);
                    $count++;
                }
            });
        }

        return $count;
    }

    private function lock(string $event): void
    {
        abort_unless(Str::isUuid($event), 404);
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 3))', [$event]);
        abort_unless(DB::table('events')->where('id', $event)->exists(), 404);
    }

    /** @return array<string,mixed>|null */
    private function owned(string $event, string $actor): ?array
    {
        $p = $this->read($event);
        if (! $p) {
            return null;
        }
        abort_unless($p['actor_id'] === $actor, 403);
        if ($p['expires_at'] <= now()->timestamp) {
            $this->fail('Cet aperçu a expiré. Rechargez le fichier.');
        }

        return $p;
    }

    private function directory(string $event): string
    {
        return Storage::disk('local')->path('imports/'.$event);
    }

    /** @return array<string,mixed>|null */
    private function read(string $event): ?array
    {
        $path = $this->directory($event).'/preview.enc';

        return is_file($path) ? json_decode(Crypt::decryptString(File::get($path)), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    /** @param array<string,mixed> $p */
    private function write(string $event, array $p): void
    {
        $path = $this->directory($event).'/preview.enc';
        $temp = $path.'.tmp';
        File::put($temp, Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)));
        chmod($temp, 0600);
        rename($temp, $path);
    }

    /** @param array<string,mixed> $p */
    private function delete(string $event, array $p): void
    {
        File::delete([$p['path'], $this->directory($event).'/preview.enc']);
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function publicPreview(array $p): array
    {
        return ['id' => $p['id'], 'event_id' => $p['event_id'], 'filename' => $p['filename'], 'create_hosts' => $p['create_hosts'], 'summary' => $p['analysis']['summary'], 'anomalies' => $p['analysis']['anomalies'], 'sample' => $p['analysis']['sample']];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
