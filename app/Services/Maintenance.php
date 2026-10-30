<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** File bornée ; le processus web n'accède jamais aux secrets de maintenance. */
final class Maintenance
{
    /** @return array<string,mixed> */
    public function preview(string $operation): array
    {
        if ($operation === 'demo') {
            return ['counts' => app(DemoCleanup::class)->run(), 'fingerprint' => app(DataLifecycle::class)->fingerprint()];
        }
        if ($operation !== 'conservation') {
            throw new RuntimeException('Opération inconnue.');
        }
        if (DB::table('settings')->where('key', 'conservation_validee')->value('value') !== '1') {
            throw new RuntimeException('La politique de conservation doit être approuvée.');
        }
        $days = app(DataRetention::class)->settings();
        $eligible = [];
        foreach (DB::table('events')->where('ends_at', '<', now())->orderBy('id')->get() as $event) {
            if (app(DataLifecycle::class)->closureReasons($event->id) !== [] || DB::table('alerts')->where('event_id', $event->id)->whereNull('resolved_at')->exists()) {
                throw new RuntimeException('Un événement terminé contient des présences, appareils ou opérations non résolus. Clôturez les événements avant le nettoyage.');
            }
            if (! in_array($event->status, ['clos', 'archive'], true)) {
                throw new RuntimeException('Un événement terminé reste ouvert. Clôturez-le avant le nettoyage de conservation.');
            }
            $elapsed = (int) Carbon::parse($event->ends_at)->diffInDays(now());
            $ids = DB::table('visitors')->where('event_id', $event->id)->select('id');
            $eligible[] = ['id' => $event->id,
                'personnes' => $elapsed >= $days['visiteurs'] && $event->anonymized_at === null ? DB::table('visitors')->where('event_id', $event->id)->count() : 0,
                'photos_echues' => $elapsed >= $days['photos'], 'historique_echu' => $elapsed >= $days['evenements'],
                'photos' => $elapsed >= $days['photos'] ? DB::table('photos')->whereIn('visitor_id', clone $ids)->count() + DB::table('identity_checks')->whereIn('visitor_id', clone $ids)->whereNotNull('document_file')->count() + DB::table('visitors')->where('event_id', $event->id)->whereNotNull('profile_photo_file')->count() : 0,
                'mouvements' => $elapsed >= $days['evenements'] && $event->retention_summary === null ? DB::table('access_events')->where('event_id', $event->id)->count() : 0,
                'notifications' => $elapsed >= $days['notifications'] ? DB::table('notifications')->where('event_id', $event->id)->count() : 0,
                'alertes' => $elapsed >= $days['alertes'] ? DB::table('alerts')->where('event_id', $event->id)->whereNotNull('resolved_at')->count() : 0,
            ];
        }

        return ['events' => $eligible, 'fingerprint' => app(DataLifecycle::class)->fingerprint(), 'retained' => ['Journal d’audit intact', 'Références statistiques conservées', 'Les fichiers détachés sont effacés après validation, avec reprise']];
    }

    public function request(string $operation, User $actor, string $fingerprint): string
    {
        $id = (string) Str::uuid();
        DB::table('maintenance_requests')->insert(['id' => $id, 'actor_id' => $actor->id, 'operation' => $operation, 'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        app(Audit::class)->append('gestion.maintenance_demandee', $actor->id, ['id' => $id, 'operation' => $operation]);

        return $id;
    }

    /** La preuve est un manifeste local fourni par l'exploitation, jamais par une requête web. */
    public function verifyBackup(string $manifest): void
    {
        if (! is_file($manifest) || is_link($manifest) || filemtime($manifest) < time() - 3600) {
            throw new RuntimeException('Preuve de sauvegarde absente ou trop ancienne (une heure maximum).');
        }
        $lines = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $types = [];
        foreach ($lines as $line) {
            if (preg_match('/^([0-9a-f]{64})  (ikoma-(base|fichiers|effectifs)-[0-9TZ]+\.(dump\.gpg|tar\.gpg|txt))$/', $line, $m) !== 1) {
                throw new RuntimeException('Manifeste non conforme.');
            }
            $path = dirname($manifest).'/'.$m[2];
            if (! is_file($path) || is_link($path) || filesize($path) === 0 || ! hash_equals($m[1], hash_file('sha256', $path))) {
                throw new RuntimeException('Sauvegarde manquante ou empreinte incorrecte.');
            }
            $types[] = $m[3];
        }
        sort($types);
        if ($types !== ['base', 'effectifs', 'fichiers']) {
            throw new RuntimeException('La sauvegarde doit couvrir la base et les fichiers.');
        }
    }

    public function execute(string $id, string $manifest): void
    {
        app(DataRetention::class)->requirePrivileged();
        try {
            $this->verifyBackup($manifest);
            DB::transaction(function () use ($id): void {
                app(DataLifecycle::class)->lock();
                $request = DB::table('maintenance_requests')->where('id', $id)->lockForUpdate()->first();
                if (! $request || $request->status !== 'en_attente') {
                    throw new RuntimeException('Demande absente ou déjà traitée.');
                }
                $actor = User::find($request->actor_id);
                if (! $actor || ! $actor->active || ! $actor->hasRole('administrateur') || ! $actor->can('comptes.gerer') || $actor->two_factor_confirmed_at === null || now()->gt($request->expires_at)) {
                    throw new RuntimeException('Droits ou confirmation expirés.');
                }
                $preview = $this->preview($request->operation);
                if (! hash_equals($request->fingerprint, $preview['fingerprint'])) {
                    throw new RuntimeException('L’aperçu est devenu obsolète. Recommencez l’analyse.');
                }
                $counts = $request->operation === 'demo' ? app(DemoCleanup::class)->run(true, $actor->id) : app(DataRetention::class)->purge();
                DB::table('maintenance_requests')->where('id', $id)->update(['status' => 'base_validee', 'result' => json_encode($counts, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                app(Audit::class)->append('gestion.maintenance_base_validee', $actor->id, ['id' => $id, 'operation' => $request->operation, 'nombres' => $counts]);
            });
            $remaining = app(FileDeletionQueue::class)->drain();
            DB::transaction(function () use ($id, $remaining): void {
                DB::table('maintenance_requests')->where('id', $id)->where('status', 'base_validee')->update(['status' => $remaining ? 'fichiers_a_reprendre' : 'terminee', 'updated_at' => now()]);
                app(Audit::class)->append($remaining ? 'gestion.fichiers_a_reprendre' : 'gestion.maintenance_terminee', null, ['id' => $id, 'fichiers_restants' => $remaining]);
            });
        } catch (\Throwable $e) {
            DB::table('maintenance_requests')->where('id', $id)->where('status', 'en_attente')->update(['status' => 'refusee', 'result' => json_encode(['message' => $e instanceof RuntimeException ? $e->getMessage() : 'Échec de maintenance. Aucune modification de base validée.'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            app(Audit::class)->append('gestion.maintenance_refusee', null, ['id' => $id, 'resultat' => 'refus']);
            throw $e;
        }
    }
}
