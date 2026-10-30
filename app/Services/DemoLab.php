<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Scénarios de test explicitement marqués, et remise à zéro des seules données métier.
 * Un compte administrateur, les migrations, les réglages techniques et l'audit restent en place.
 */
final class DemoLab
{
    public const SCENARIOS = [
        'entreprise' => 'Accueil d’entreprise',
        'evenement' => 'Événement et VIP',
        'formation' => 'Formation et groupes',
    ];

    public static function authorize(User $actor): void
    {
        abort_unless(app()->environment('demo', 'local', 'testing'), 403, 'Laboratoire indisponible en production.');
        abort_unless($actor->active && $actor->two_factor_confirmed_at !== null
            && $actor->hasRole('administrateur') && $actor->can('comptes.gerer'), 403);
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        return [
            'events' => DB::table('events')->count(),
            'visitors' => DB::table('visitors')->count(),
            'badges' => DB::table('badges')->count(),
            'groups' => DB::table('groups')->count(),
            'devices' => DB::table('devices')->count(),
            'demo_events' => DB::table('events')->where('is_demo', true)->count(),
        ];
    }

    /** Crée un scénario complet mais aucun compte fictif ni accès au monde réel. */
    public function generate(string $scenario, int $quantity, User $actor): string
    {
        self::authorize($actor);
        if (! isset(self::SCENARIOS[$scenario]) || ! in_array($quantity, [20, 100, 300, 1000], true)) {
            throw ValidationException::withMessages(['scenario' => 'Choisissez un scénario et 20, 100, 300 ou 1 000 personnes.']);
        }

        return DB::transaction(function () use ($scenario, $quantity, $actor): string {
            DB::statement('SELECT pg_advisory_xact_lock(731903)');
            $event = (string) Str::uuid();
            $start = now()->subHour();
            $end = $start->copy()->addDays(7);
            $now = now();
            DB::table('events')->insert([
                'id' => $event, 'name' => '[DÉMO] '.self::SCENARIOS[$scenario].' — '.($now->format('d/m H:i')),
                'site' => 'Site fictif IKOMA', 'starts_at' => $start,
                'ends_at' => $end, 'is_demo' => true, 'demo_scenario' => $scenario,
                'demo_created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $zoneIds = [];
            foreach (['Accueil', 'Zone principale', 'Zone VIP', 'Zone technique'] as $index => $name) {
                $id = (string) Str::uuid();
                $zoneIds[] = $id;
                DB::table('zones')->insert(['id' => $id, 'event_id' => $event, 'name' => $name,
                    'est_accueil' => $index === 0, 'created_at' => $now, 'updated_at' => $now]);
            }
            $categoryIds = [];
            $categorySpecs = [
                ['Visiteur', '#0066B3', false],
                ['VIP', '#F75C03', true],
                ['Prestataire', '#64748B', false],
                ['Stagiaire', '#168354', false],
            ];
            foreach ($categorySpecs as $index => [$name, $color, $priority]) {
                $id = (string) Str::uuid();
                $categoryIds[] = $id;
                DB::table('categories')->insert(['id' => $id, 'event_id' => $event, 'name' => $name,
                    'color' => $color, 'priority_circuit' => $priority, 'created_at' => $now, 'updated_at' => $now]);
                foreach ([$zoneIds[0], $zoneIds[$index === 1 ? 2 : ($index === 2 ? 3 : 1)]] as $zone) {
                    DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $event,
                        'category_id' => $id, 'zone_id' => $zone, 'created_at' => $now, 'updated_at' => $now]);
                }
            }

            $hostId = (string) Str::uuid();
            DB::table('hosts')->insert(['id' => $hostId, 'event_id' => $event,
                'name' => 'Hôte de démonstration', 'service' => 'Service fictif',
                'created_at' => $now, 'updated_at' => $now]);

            $deviceId = (string) Str::uuid();
            DB::table('devices')->insert(['id' => $deviceId, 'event_id' => $event,
                'name' => 'Tablette de démonstration', 'station' => 'Accueil fictif',
                'created_at' => $now, 'updated_at' => $now]);

            $groupId = null;
            if ($scenario === 'formation') {
                $groupId = (string) Str::uuid();
                DB::table('groups')->insert(['id' => $groupId, 'event_id' => $event,
                    'name' => 'Groupe de formation fictif', 'bus_number' => 'CAR-DEMO',
                    'created_at' => $now, 'updated_at' => $now]);
            }

            $people = [];
            $members = [];
            $perCategory = array_fill(0, 4, 0);
            for ($i = 1; $i <= $quantity; $i++) {
                $id = (string) Str::uuid();
                $isTrainee = $scenario === 'formation' && $i > (int) floor($quantity / 2);
                $categoryIndex = $isTrainee ? 3 : ($scenario === 'evenement' && $i % 10 === 0 ? 1 : 0);
                $perCategory[$categoryIndex]++;
                $people[] = ['id' => $id, 'event_id' => $event,
                    'full_name' => 'Personne fictive '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'organisation' => 'Organisation de démonstration',
                    'host_id' => $hostId, 'category_id' => $categoryIds[$categoryIndex],
                    'group_id' => $isTrainee ? $groupId : null,
                    'valid_from' => $start, 'valid_until' => $end,
                    'source' => 'listé', 'status' => 'attendu', 'created_at' => $now, 'updated_at' => $now];
                if ($isTrainee) {
                    $members[] = ['id' => (string) Str::uuid(), 'group_id' => $groupId,
                        'visitor_id' => $id, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($people, 200) as $chunk) {
                DB::table('visitors')->insert($chunk);
            }
            foreach (array_chunk($members, 200) as $chunk) {
                DB::table('group_members')->insert($chunk);
            }

            $number = 0;
            $badgeNumber = 0;
            // Un lot par catégorie réellement utilisée, pour que les VIP et
            // stagiaires puissent aussi parcourir le circuit d'accueil.
            foreach ($perCategory as $categoryIndex => $expected) {
                if ($expected === 0) {
                    continue;
                }
                $manufactured = $expected + (int) ceil($expected / 10);
                $batchId = (string) Str::uuid();
                DB::table('badge_batches')->insert([
                    'id' => $batchId, 'event_id' => $event,
                    'category_id' => $categoryIds[$categoryIndex],
                    'name' => 'Lot DÉMO — '.$categorySpecs[$categoryIndex][0],
                    'quantity' => $manufactured, 'margin' => $manufactured - $expected,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $badges = [];
                for ($i = 0; $i < $manufactured; $i++) {
                    $badgeNumber++;
                    $badges[] = ['id' => (string) Str::uuid(), 'event_id' => $event,
                        'category_id' => $categoryIds[$categoryIndex], 'badge_batch_id' => $batchId,
                        'code' => 'B-'.str_pad((string) $badgeNumber, 4, '0', STR_PAD_LEFT),
                        'qr_token' => bin2hex(random_bytes(32)),
                        'status' => 'Disponible', 'created_at' => $now, 'updated_at' => $now];
                }
                foreach (array_chunk($badges, 200) as $chunk) {
                    DB::table('badges')->insert($chunk);
                }
                $number += $manufactured;
            }
            app(Audit::class)->append('demo.scenario_cree', $actor->id, [
                'event_id' => $event, 'scenario' => $scenario, 'personnes' => $quantity, 'badges' => $number,
            ]);

            return $event;
        });
    }

    /** @param list<string> $eventIds */
    private function queueFiles(array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }
        $people = DB::table('visitors')->whereIn('event_id', $eventIds)->select('id');
        $paths = [
            ...DB::table('visitors')->whereIn('event_id', $eventIds)->whereNotNull('profile_photo_file')->pluck('profile_photo_file')->all(),
            ...DB::table('photos')->whereIn('visitor_id', clone $people)->pluck('file_reference')->all(),
            ...DB::table('identity_checks')->whereIn('visitor_id', clone $people)->whereNotNull('document_file')->pluck('document_file')->all(),
        ];
        foreach (array_unique($paths) as $path) {
            app(FileDeletionQueue::class)->enqueue($path);
        }
    }

    public function clearScenario(string $eventId, User $actor): void
    {
        self::authorize($actor);
        DB::transaction(function () use ($eventId, $actor): void {
            $event = DB::table('events')->where('id', $eventId)->where('is_demo', true)->first();
            abort_unless($event, 404, 'Scénario de démonstration introuvable.');
            $this->queueFiles([$eventId]);
            DB::select('SELECT public.ikoma_reset_demo_event(CAST(? AS uuid))', [$eventId]);
            app(Audit::class)->append('demo.scenario_reinitialise', $actor->id, ['event_id' => $eventId]);
        });
        app(FileDeletionQueue::class)->drain();
    }

    /** Efface tous les événements de cette installation DEMO, jamais les comptes ou la configuration. */
    public function clearBusiness(User $actor, bool $clearAccounts = false): array
    {
        self::authorize($actor);
        $counts = [];
        DB::transaction(function () use ($actor, $clearAccounts, &$counts): void {
            $counts = $this->totals();
            $eventIds = DB::table('events')->pluck('id')->all();
            $this->queueFiles($eventIds);
            DB::select('SELECT public.ikoma_reset_demo_business(CAST(? AS uuid), ?)', [$actor->id, $clearAccounts]);
            app(Audit::class)->append('demo.remise_zero_metier', $actor->id, ['nombres' => $counts]);
        });
        app(FileDeletionQueue::class)->drain();

        return $counts;
    }
}
