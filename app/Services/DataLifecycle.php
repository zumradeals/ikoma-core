<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Contrat commun d'analyse et d'exécution ; aucune commande arbitraire ni cascade implicite. */
final class DataLifecycle
{
    public const ENTITIES = ['personne' => ['visitors', 'listes.importer'], 'badge' => ['badges', 'badges.gerer'], 'lot' => ['badge_batches', 'badges.gerer'], 'evenement' => ['events', 'evenements.gerer'], 'categorie' => ['categories', 'categories.gerer'], 'zone' => ['zones', 'zones.gerer'], 'groupe' => ['groups', 'groupes.gerer']];

    public const TABLES = ['events', 'hosts', 'devices', 'visitors', 'badge_batches', 'badges', 'badge_assignments', 'access_events', 'identity_checks', 'photos', 'sync_operations', 'sync_conflicts', 'notifications', 'alerts', 'refusals', 'import_batches', 'groups', 'group_members', 'categories', 'zones', 'category_zone', 'settings'];

    public function lock(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('LOCK TABLE '.implode(', ', array_diff(self::TABLES, ['access_events', 'identity_checks'])).' IN EXCLUSIVE MODE');
    }

    /** Une empreinte conservatrice invalide aussi l'aperçu en cas d'activité concurrente dans l'installation. */
    public function fingerprint(): string
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $where = $table === 'settings' ? " WHERE key NOT LIKE 'sauvegarde_%'" : '';
            $parts[] = DB::selectOne("SELECT md5(coalesce(string_agg(md5(row_to_json(t)::text), '' ORDER BY row_to_json(t)::text), '')) AS hash FROM {$table} t{$where}")->hash;
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return array<string,mixed> */
    public function inspect(string $entity, string $id, User $actor): array
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        [$table, $permission] = self::ENTITIES[$entity];
        abort_unless($actor->can($permission), 403);
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row !== null, 404);
        $reasons = [];
        $counts = [$table => 1];
        $has = static fn (string $table, string $column, string $value): bool => DB::table($table)->where($column, $value)->exists();
        if ($entity === 'personne') {
            if (! in_array($row->status, [null, 'attendu'], true)) {
                $reasons[] = 'Cette personne a un état opérationnel : sa fiche doit être conservée.';
            }
            foreach (['access_events', 'identity_checks', 'photos', 'refusals', 'badge_assignments', 'group_members', 'notifications', 'sync_operations', 'sync_conflicts'] as $dependency) {
                if ($has($dependency, 'visitor_id', $id)) {
                    $reasons[] = 'Cette personne possède un historique ou une dépendance protégée. Vous pouvez la retirer après sa sortie, mais pas supprimer sa fiche.';
                    break;
                }
            }
            if ($row->profile_photo_file !== null || $has('badges', 'printed_for_visitor_id', $id) || $row->group_id !== null) {
                $reasons[] = 'Un portrait, un groupe ou un badge nominatif impose de conserver cette fiche.';
            }
        } elseif ($entity === 'badge') {
            $batch = DB::table('badge_batches')->where('id', $row->badge_batch_id)->first();
            if ($row->status !== 'Disponible' || $row->reserved_device_id !== null
                || $row->reserved_group_id !== null || $row->printed_for_visitor_id !== null
                || $batch?->print_exposed_at !== null
                || $has('badge_assignments', 'badge_id', $id)
                || $has('access_events', 'badge_id', $id)
                || $has('groups', 'badge_batch_id', $row->badge_batch_id)) {
                $reasons[] = 'Badge déjà utilisé, réservé, nominatif ou présenté à l’impression. Il peut être annulé par un superviseur, mais pas supprimé individuellement.';
            }
        } elseif ($entity === 'lot') {
            $badges = DB::table('badges')->where('badge_batch_id', $id);
            $counts['badges'] = (clone $badges)->count();
            if ($row->print_exposed_at !== null) {
                $reasons[] = 'Ce lot a été présenté à l’impression ou son impression antérieure ne peut pas être exclue. Ses cartes peuvent circuler.';
            }
            if ((clone $badges)->where(fn ($q) => $q->where('status', '<>', 'Disponible')->orWhereNotNull('reserved_device_id')->orWhereNotNull('reserved_group_id')->orWhereNotNull('printed_for_visitor_id'))->exists()
                || DB::table('badge_assignments')->whereIn('badge_id', (clone $badges)->select('id'))->exists()
                || DB::table('access_events')->whereIn('badge_id', (clone $badges)->select('id'))->exists()
                || $has('groups', 'badge_batch_id', $id)
                || DB::table('devices')->where('event_id', $row->event_id)->whereNotNull('enrolled_at')->exists()) {
                $reasons[] = 'Lot réservé, nominatif, utilisé ou exposé à une tablette : suppression interdite.';
            }
        } elseif ($entity === 'evenement') {
            if ($row->status !== null) {
                $reasons[] = 'Cet événement possède un état opérationnel : conservez ses références.';
            }
            foreach (['visitors', 'hosts', 'devices', 'badge_batches', 'badges', 'groups', 'import_batches', 'access_events', 'badge_assignments', 'notifications', 'alerts', 'sync_conflicts'] as $dependency) {
                if ($has($dependency, 'event_id', $id)) {
                    $reasons[] = 'Cet événement contient des personnes, des équipements, des badges ou une activité. Seule une préparation inutilisée peut être supprimée.';
                    break;
                }
            }
            foreach (['category_zone', 'categories', 'zones'] as $configuration) {
                $counts[$configuration] = DB::table($configuration)->where('event_id', $id)->count();
            }
        } elseif ($entity === 'categorie') {
            foreach (['visitors', 'badges', 'badge_batches'] as $dependency) {
                if ($has($dependency, 'category_id', $id)) {
                    $reasons[] = 'Cette catégorie est utilisée par une personne ou un badge.';
                    break;
                }
            }
            $counts['category_zone'] = DB::table('category_zone')->where('category_id', $id)->count();
        } elseif ($entity === 'zone') {
            if ($row->est_accueil || $has('category_zone', 'zone_id', $id) || $has('devices', 'event_id', $row->event_id) || $has('access_events', 'event_id', $row->event_id)) {
                $reasons[] = 'Zone d’accueil obligatoire, règle d’accès ou dépendance opérationnelle : suppression interdite.';
            }
        } elseif ($entity === 'groupe') {
            if ($row->opened_at !== null || $row->departed_at !== null || $row->arrival_closed_at !== null || $row->badge_batch_id !== null || $has('group_members', 'group_id', $id) || $has('visitors', 'group_id', $id) || $has('badges', 'reserved_group_id', $id)) {
                $reasons[] = 'Ce groupe contient des membres, des réservations ou un parcours commencé.';
            }
        }

        return ['entity' => $entity, 'id' => $id, 'label' => $row->name ?? $row->full_name ?? $row->code ?? $id, 'event_id' => $entity === 'evenement' ? $id : $row->event_id, 'allowed' => $reasons === [], 'reasons' => $reasons, 'counts' => $counts, 'irreversible' => true, 'retained' => ['Journal d’audit', 'Identifiants de badges retirés, non réattribuables'], 'fingerprint' => $this->fingerprint()];
    }

    /** @return array<string,int> */
    public function delete(string $entity, string $id, User $actor, string $fingerprint): array
    {
        return DB::transaction(function () use ($entity, $id, $actor, $fingerprint): array {
            DB::statement('SELECT pg_advisory_xact_lock(731903)');
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->active && $actor->two_factor_confirmed_at !== null, 403);
            $this->lock();
            $report = $this->inspect($entity, $id, $actor);
            if (! hash_equals($report['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['operation' => 'L’aperçu a changé. Recommencez l’analyse.']);
            }
            if (! $report['allowed']) {
                throw ValidationException::withMessages(['operation' => implode(' ', $report['reasons'])]);
            }
            if ($entity === 'lot') {
                DB::table('badges')->where('badge_batch_id', $id)->delete();
            } elseif ($entity === 'evenement') {
                foreach (['category_zone', 'categories', 'zones'] as $table) {
                    DB::table($table)->where('event_id', $id)->delete();
                }
            } elseif ($entity === 'categorie') {
                DB::table('category_zone')->where('category_id', $id)->delete();
            }
            $batchId = $entity === 'badge' ? DB::table('badges')->where('id', $id)->value('badge_batch_id') : null;
            DB::table(self::ENTITIES[$entity][0])->where('id', $id)->delete();
            if ($batchId !== null) {
                DB::table('badge_batches')->where('id', $batchId)->update([
                    'quantity' => DB::raw('GREATEST(quantity - 1, 0)'),
                    'margin' => DB::raw('GREATEST(margin - 1, 0)'),
                    'updated_at' => now(),
                ]);
            }
            if ($entity !== 'evenement') {
                DB::table('events')->where('id', $report['event_id'])->increment('list_revision');
            }
            app(Audit::class)->append('gestion.suppression', $actor->id, ['entite' => $entity, 'id' => $id, 'event_id' => $report['event_id'], 'nombres' => $report['counts'], 'resultat' => 'reussite']);

            return $report['counts'];
        });
    }

    /** @return list<string> */
    public function closureReasons(string $id): array
    {
        $reasons = [];
        if (app(Presence::class)->count($id) > 0 || DB::table('visitors')->where('event_id', $id)->where('status', 'present')->exists()) {
            $reasons[] = 'Des personnes sont encore présentes. Enregistrez leurs sorties réelles.';
        }
        if (DB::table('devices')->where('event_id', $id)->whereNotNull('enrolled_at')->where(fn ($q) => $q->whereNull('revoked_at')->orWhereNull('wipe_acknowledged_at'))->exists()) {
            $reasons[] = 'Synchronisez les tablettes, révoquez-les puis reconnectez-les pour confirmer leur effacement. Les opérations locales inconnues ne peuvent pas être déclarées résolues.';
        }
        if (DB::table('groups')->where('event_id', $id)->whereNotNull('opened_at')->whereNull('arrival_closed_at')->exists()) {
            $reasons[] = 'Un parcours de groupe reste ouvert. Terminez son arrivée et résolvez ses écarts.';
        }
        if (DB::table('devices')->where('event_id', $id)->whereNotNull('enrollment_hash')->where('enrollment_expires_at', '>', now())->exists()
            || DB::table('sync_conflicts')->where('event_id', $id)->whereNull('resolved_at')->exists()
            || DB::table('sync_operations')->whereIn('device_id', DB::table('devices')->where('event_id', $id)->select('id'))->whereNull('processed_at')->exists()
            || DB::table('badge_assignments')->where('event_id', $id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()
            || DB::table('notifications')->where('event_id', $id)->whereNull('decided_at')->whereIn('type', ['non_liste', 'hors_periode'])->exists()) {
            $reasons[] = 'Des réservations d’enrôlement, restitutions, décisions ou synchronisations restent à résoudre.';
        }

        return $reasons;
    }

    public function transition(string $id, string $action, User $actor): void
    {
        abort_unless($actor->can('evenements.gerer'), 403);
        DB::transaction(function () use ($id, $action, $actor): void {
            $this->lock();
            $event = DB::table('events')->where('id', $id)->first();
            abort_unless($event !== null, 404);
            if ($action === 'archiver') {
                $reasons = $event->status === 'clos' ? $this->closureReasons($id) : ['Clôturez cet événement avant de l’archiver.'];
            } else {
                $reasons = in_array($event->status, ['clos', 'archive'], true) ? ['Cet événement est déjà clôturé.'] : $this->closureReasons($id);
            }
            if ($reasons !== []) {
                throw ValidationException::withMessages(['operation' => implode(' ', $reasons)]);
            }
            DB::table('events')->where('id', $id)->update(['status' => $action === 'archiver' ? 'archive' : 'clos', 'updated_at' => now(), 'list_revision' => DB::raw('list_revision + 1')]);
            app(Audit::class)->append('gestion.'.$action, $actor->id, ['event_id' => $id, 'resultat' => 'reussite']);
        });
    }
}
