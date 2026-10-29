<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Retire uniquement le jeu fixe créé par DemoSeeder et ses comptes nominaux
 * seedés. Une opération réelle enregistrée entraîne un refus intégral.
 */
final class PurgeDemoData extends Command
{
    protected $signature = 'demo:purger {--confirmer : Exécuter le nettoyage après sauvegarde vérifiée}';

    protected $description = 'Inspecte puis retire le jeu de démonstration IKOMA sans purger les données réelles';

    public function handle(): int
    {
        try {
            return DB::transaction(function (): int {
                $id = static fn (string $key): string => DemoSeeder::id($key);
                $eventId = $id('event');
                $event = DB::table('events')->where('id', $eventId)->lockForUpdate()->first();
                $emails = array_map(static fn (string $role): string => $role.'@exemple.test', array_keys(config('roles')));
                $accounts = User::query()->whereIn('email', $emails)->lockForUpdate()->get();

                foreach ($accounts as $account) {
                    $role = substr($account->email, 0, -strlen('@exemple.test'));
                    if ($account->name !== 'Compte fictif '.$role) {
                        throw new RuntimeException('Un compte @exemple.test a été renommé : suppression automatique refusée.');
                    }
                }

                if ($event) {
                    if ($event->name !== 'Exercice fictif IKOMA — démonstration' || $event->site !== 'Site fictif de démonstration') {
                        throw new RuntimeException('Événement de démonstration modifié : suppression refusée.');
                    }

                    $expected = [
                        'zones' => array_map($id, array_map(static fn ($i) => 'zone'.$i, range(0, 5))),
                        'categories' => array_map($id, array_map(static fn ($i) => 'category'.$i, range(0, 3))),
                        'hosts' => array_map($id, array_map(static fn ($i) => 'host'.$i, range(0, 2))),
                        'devices' => [$id('device')],
                        'badge_batches' => [$id('batch')],
                        'groups' => [$id('group')],
                        'visitors' => array_map($id, array_map(static fn ($i) => 'visitor'.$i, range(0, 299))),
                        'badges' => array_map($id, array_map(static fn ($i) => 'badge'.$i, range(1, 400))),
                    ];

                    foreach ($expected as $table => $ids) {
                        $actual = DB::table($table)->where('event_id', $eventId)->pluck('id')->all();
                        sort($actual);
                        sort($ids);
                        if ($actual !== $ids) {
                            throw new RuntimeException("Données ajoutées ou manquantes dans {$table} : vérification manuelle obligatoire.");
                        }
                    }
                    $expectedPivots = [];
                    $allowed = [[0, 1, 2], [0, 1, 4], [0, 3, 4], [0, 4, 5]];
                    foreach ($allowed as $i => $zones) {
                        foreach ($zones as $zone) {
                            $expectedPivots[] = $id('pivot'.$i.'-'.$zone);
                        }
                    }
                    $actualPivots = DB::table('category_zone')->where('event_id', $eventId)->pluck('id')->all();
                    sort($expectedPivots);
                    sort($actualPivots);
                    if ($expectedPivots !== $actualPivots) {
                        throw new RuntimeException('Liaisons catégories/zones modifiées : suppression refusée.');
                    }

                    $members = DB::table('group_members')->where('group_id', $id('group'))->pluck('id')->all();
                    $expectedMembers = array_map($id, array_map(static fn ($i) => 'member'.$i, range(260, 299)));
                    sort($members);
                    sort($expectedMembers);
                    if ($members !== $expectedMembers) {
                        throw new RuntimeException('Composition du car modifiée : suppression refusée.');
                    }

                    // Pas d'effacement d'activité réelle, même si elle se trouve
                    // malencontreusement dans l'événement initialement fictif.
                    foreach (['import_batches', 'badge_assignments', 'access_events', 'alerts', 'notifications', 'sync_conflicts'] as $table) {
                        if (DB::table($table)->where('event_id', $eventId)->exists()) {
                            throw new RuntimeException("Activité détectée dans {$table} : suppression refusée.");
                        }
                    }
                    $visitorIds = array_map($id, array_map(static fn ($i) => 'visitor'.$i, range(0, 299)));
                    foreach (['identity_checks', 'photos', 'refusals'] as $table) {
                        if (DB::table($table)->whereIn('visitor_id', $visitorIds)->exists()) {
                            throw new RuntimeException("Activité détectée dans {$table} : suppression refusée.");
                        }
                    }
                    if (DB::table('sync_operations')->where('device_id', $id('device'))
                        ->orWhereIn('visitor_id', $visitorIds)->exists()) {
                        throw new RuntimeException('Opérations de synchronisation détectées : suppression refusée.');
                    }
                    if (DB::table('badges')->where('event_id', $eventId)->where('status', '!=', 'Disponible')->exists()
                        || DB::table('group_members')->where('group_id', $id('group'))
                            ->where(static function ($q) {
                                $q->whereNotNull('boarded_at')->orWhereNotNull('arrived_at')
                                    ->orWhereNotNull('called_at')->orWhereNotNull('checked_at')
                                    ->orWhereNotNull('confirmed_at')->orWhereNotNull('absent_at');
                            })->exists()
                        || DB::table('devices')->where('id', $id('device'))
                            ->where(static function ($q) {
                                $q->whereNotNull('enrolled_at')->orWhereNotNull('last_synced_at')
                                    ->orWhereNotNull('enrollment_hash');
                            })->exists()
                        || (int) ($event->list_revision ?? 0) !== 0) {
                        throw new RuntimeException('Utilisation opérationnelle détectée : suppression refusée.');
                    }
                }

                $counts = [
                    'evenements' => $event ? 1 : 0,
                    'visiteurs' => $event ? 300 : 0,
                    'badges' => $event ? 400 : 0,
                    'comptes_fictifs' => $accounts->count(),
                ];
                $this->line(json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if (! $this->option('confirmer')) {
                    $this->info('Simulation uniquement. Réalisez une sauvegarde complète, créez un administrateur réel avec 2FA, puis relancez avec --confirmer.');

                    return self::SUCCESS;
                }

                // On ne supprime jamais le dernier administrateur disponible.
                $realAdmin = User::query()->where('active', true)->whereNotIn('email', $emails)
                    ->get()->contains(static fn (User $user): bool => $user->hasRole('administrateur')
                        && $user->two_factor_confirmed_at !== null);
                if (! $realAdmin) {
                    throw new RuntimeException('Aucun administrateur réel actif avec 2FA confirmée : suppression refusée.');
                }

                if ($event) {
                    DB::table('group_members')->where('group_id', $id('group'))->delete();
                    DB::table('visitors')->where('event_id', $eventId)->delete();
                    DB::table('badges')->where('event_id', $eventId)->delete();
                    DB::table('groups')->where('event_id', $eventId)->delete();
                    DB::table('badge_batches')->where('event_id', $eventId)->delete();
                    DB::table('devices')->where('event_id', $eventId)->delete();
                    DB::table('hosts')->where('event_id', $eventId)->delete();
                    DB::table('category_zone')->where('event_id', $eventId)->delete();
                    DB::table('categories')->where('event_id', $eventId)->delete();
                    DB::table('zones')->where('event_id', $eventId)->delete();
                    DB::table('events')->where('id', $eventId)->delete();
                }

                $fakeIds = $accounts->pluck('id')->all();
                if ($fakeIds !== []) {
                    DB::table('sessions')->whereIn('user_id', $fakeIds)->delete();
                    DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $fakeIds)->delete();
                    DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $fakeIds)->delete();
                    DB::table('users')->whereIn('id', $fakeIds)->delete();
                }
                app(Audit::class)->append('demo.donnees_purgees', null, $counts);
                $this->info('Jeu fictif retiré. Les autres événements, utilisateurs et le journal d’audit sont conservés.');

                return self::SUCCESS;
            });
        } catch (Throwable $e) {
            $this->error('Purge interrompue : '.$e->getMessage());
            $this->line('Aucune suppression partielle n’est validée : transaction annulée.');

            return self::FAILURE;
        }
    }
}
