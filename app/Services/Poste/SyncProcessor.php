<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Alerts;
use App\Services\Audit;
use App\Services\Groups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rejoue sur le serveur la file d'opérations d'une tablette. Chaque opération est idempotente (sync_operations) ; une opération
 * qui ne passe plus (badge pris entre-temps, visiteur déjà entré, agent désactivé…) n'est jamais écrasée : elle est mise en
 * quarantaine dans sync_conflicts, journalisée, et les autres opérations continuent.
 */
final class SyncProcessor
{
    public const MAX_OPERATIONS = 50;

    private const PERMISSIONS = ['identite' => 'visiteurs.verifier', 'valider' => 'visiteurs.valider', 'refuser' => 'visiteurs.refuser', 'badge' => 'badges.associer', 'entree' => 'visiteurs.valider', 'non_liste' => 'visiteurs.enregistrer_non_liste', 'controle' => 'badges.controler', 'sortie' => 'badges.controler', 'groupe_controle' => 'groupes.appel_nominal', 'groupe_badge' => 'groupes.remettre_badges', 'groupe_depart' => 'groupes.remettre_badges', 'groupe_arrivee' => 'badges.controler', 'groupe_arrivee_fin' => 'badges.controler'];

    public function __construct(private readonly Desk $desk, private readonly Audit $audit, private readonly SiteControl $site, private readonly Groups $groups) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array{revoked:true}|array{results:list<array<string,mixed>>,summary:array<string,int>,clock_skew_seconds:?int}
     */
    public function process(Device $device, User $session, array $operations, ?string $sentAt): array
    {
        return DB::transaction(function () use ($device, $session, $operations, $sentAt): array {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($locked->revoked_at !== null) {
                return ['revoked' => true];
            }

            return $this->batch($locked, $session, $operations, $sentAt);
        });
    }

    /** @param list<array<string, mixed>> $operations
     * @return array{revoked:true}|array{results:list<array<string,mixed>>,summary:array<string,int>,clock_skew_seconds:?int}
     */
    private function batch(Device $device, User $session, array $operations, ?string $sentAt): array
    {
        usort($operations, fn ($a, $b) => ((int) ($a['seq'] ?? 0)) <=> ((int) ($b['seq'] ?? 0)));
        $results = [];
        $applied = 0;
        $conflicts = 0;
        foreach ($operations as $operation) {
            $result = $this->one($device, $operation);
            if (($result['code'] ?? null) === 'appareil_revoque') {
                return ['revoked' => true];
            }
            $results[] = $result;
            $result['status'] === 'applique' ? $applied++ : $conflicts++;
        }
        $skew = $this->skew($sentAt);
        $threshold = (int) (DB::table('settings')->where('key', 'poste_ecart_horloge_secondes')->value('value') ?? 300);
        DB::transaction(function () use ($device, $session, $operations, $applied, $conflicts, $skew, $threshold): void {
            DB::table('devices')->where('id', $device->id)->update(['last_synced_at' => now(), 'updated_at' => now()]);
            if ($skew !== null && abs($skew) > $threshold) {
                $conflict = (string) Str::uuid();
                DB::table('sync_conflicts')->insert(['id' => $conflict, 'event_id' => $device->event_id, 'device_id' => $device->id, 'agent_id' => $session->id, 'kind' => 'horloge', 'reason' => 'horloge_suspecte', 'details' => json_encode(['ecart_secondes' => $skew, 'seuil_secondes' => $threshold]), 'created_at' => now(), 'updated_at' => now()]);
                app(Alerts::class)->raise($device->event_id, 'conflit_sync', 'normale', 'conflit', $conflict, 'L’horloge d’une tablette est décalée.', 'conflit:'.$conflict);
            }
            $this->audit->append('sync.recue', $session->id, ['device_id' => $device->id, 'recues' => count($operations), 'appliquees' => $applied, 'conflits' => $conflicts, 'ecart_horloge_s' => $skew]);
        });

        return ['results' => $results, 'summary' => ['received' => count($operations), 'applied' => $applied, 'conflicts' => $conflicts], 'clock_skew_seconds' => $skew];
    }

    /** Écart entre l'heure serveur et l'heure déclarée de la tablette à l'envoi (positif : la tablette retarde). */
    private function skew(?string $sentAt): ?int
    {
        if ($sentAt === null) {
            return null;
        }
        try {
            return (int) round(now()->getTimestamp() - Carbon::parse($sentAt)->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function one(Device $device, array $op): array
    {
        $id = (string) ($op['id'] ?? '');
        $type = (string) ($op['type'] ?? '');
        $payload = is_array($op['payload'] ?? null) ? $op['payload'] : [];
        if (! Str::isUuid($id) || ! isset(self::PERMISSIONS[$type]) || ! Str::isUuid((string) ($op['agent_id'] ?? ''))) {
            return ['id' => $id, 'status' => 'conflit', 'code' => 'operation_invalide', 'message' => 'Opération invalide.'];
        }
        if (User::find($op['agent_id']) === null) {
            return ['id' => $id, 'status' => 'conflit', 'code' => 'agent_inconnu', 'message' => 'Agent inconnu : l’opération est refusée.'];
        }
        $hash = hash('sha256', $type.'|'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).'|'.($op['agent_id']));

        return DB::transaction(function () use ($device, $op, $id, $type, $payload, $hash): array {
            $now = now();
            $clientTime = $this->time($op['client_time'] ?? null);
            $visitorId = isset($payload['visitor_id']) && Str::isUuid((string) $payload['visitor_id']) ? (string) $payload['visitor_id'] : null;
            $sequence = filter_var($op['seq'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sequence === false) {
                return ['id' => $id, 'status' => 'conflit', 'code' => 'operation_invalide', 'message' => 'Séquence invalide.'];
            }
            if (! DB::table('sync_operations')->where('id', $id)->exists()) {
                if ($sequence <= (int) DB::table('devices')->where('id', $device->id)->value('sync_seq')) {
                    DB::table('devices')->where('id', $device->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
                    $conflict = (string) Str::uuid();
                    DB::table('sync_conflicts')->insert(['id' => $conflict, 'event_id' => $device->event_id, 'device_id' => $device->id, 'kind' => 'conflit', 'reason' => 'appareil_clone', 'details' => json_encode(['seq' => $sequence]), 'created_at' => now(), 'updated_at' => now()]);
                    app(Alerts::class)->raise($device->event_id, 'conflit_sync', 'critique', 'conflit', $conflict, 'Séquences incompatibles : appareil révoqué, vérification nécessaire.', 'clone:'.$device->id.':'.$device->enrolled_at);
                    $this->audit->append('poste.clone_suspect', null, ['device_id' => $device->id, 'seq' => $sequence]);

                    return ['id' => $id, 'code' => 'appareil_revoque'];
                }
                DB::table('devices')->where('id', $device->id)->update(['sync_seq' => $sequence]);
            }
            $inserted = DB::table('sync_operations')->insertOrIgnore(['id' => $id, 'device_id' => $device->id, 'user_id' => $op['agent_id'], 'type' => $type, 'payload_hash' => $hash, 'seq' => (int) ($op['seq'] ?? 0), 'client_time' => $clientTime, 'visitor_id' => $visitorId, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if ($inserted === 0) {
                $row = DB::table('sync_operations')->where('id', $id)->first();
                if ($row->payload_hash !== $hash || $row->device_id !== $device->id) {
                    return ['id' => $id, 'status' => 'conflit', 'code' => 'operation_conflit', 'message' => 'Identifiant d’opération déjà utilisé pour une autre action.'];
                }

                return [...(array) json_decode((string) $row->result, true), 'id' => $id, 'replayed' => true];
            }
            try {
                $agent = User::findOrFail($op['agent_id']);
                if (! $agent->active || ! $agent->hasRole('agent_accueil')) {
                    throw new DeskRefused('agent_desactive', 'Le compte de l’agent est désactivé ou inconnu : l’opération est mise en quarantaine.', 409);
                }
                if (! $agent->can(self::PERMISSIONS[$type])) {
                    throw new DeskRefused('non_autorise', 'Action non autorisée pour ce compte.', 403);
                }
                $this->checkRevision($device, $id, $visitorId, $op);
                $result = DB::transaction(fn () => $this->execute($device, $agent, $type, $payload, $clientTime));
                if (($result['refus'] ?? false) === true) {
                    // Contrôle ou sortie jugé par le serveur : le refus (événement, journal) est déjà écrit ; l'opération de la tablette passe en conflit.
                    throw new DeskRefused((string) $result['reason'], (string) $result['message'], 409);
                }
                $body = ['status' => 'applique', ...$result];
                $status = 200;
            } catch (DeskRefused $e) {
                $body = ['status' => 'conflit', 'code' => $e->code_, 'message' => $e->getMessage()];
                $status = $e->status;
                $this->quarantine($device, $id, $type, $op, $visitorId, $payload, $e->code_);
            }
            DB::table('sync_operations')->where('id', $id)->update(['status_code' => $status, 'result' => json_encode($body, JSON_THROW_ON_ERROR), 'processed_at' => now(), 'updated_at' => now()]);

            return ['id' => $id, ...$body];
        });
    }

    /**
     * « Fiche modifiée entre-temps » : la fiche a changé depuis la copie de la tablette, par un autre poste.
     *
     * @param  array<string, mixed>  $op
     */
    private function checkRevision(Device $device, string $operationId, ?string $visitorId, array $op): void
    {
        $rev = $this->time($op['visitor_rev'] ?? null);
        if ($visitorId === null || $rev === null || ($op['type'] ?? '') === 'non_liste') {
            return;
        }
        $current = DB::table('visitors')->where('id', $visitorId)->value('updated_at');
        if ($current === null || Carbon::parse($current)->lte(Carbon::parse($rev))) {
            return;
        }
        $ownEarlier = DB::table('sync_operations')->where('device_id', $device->id)->where('visitor_id', $visitorId)->where('id', '<>', $operationId)->where('status_code', 200)->exists();
        if (! $ownEarlier) {
            throw new DeskRefused('fiche_modifiee', 'La fiche a été modifiée depuis la copie de la tablette : vérifiez-la avant de rejouer l’opération.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function execute(Device $device, User $agent, string $type, array $p, ?string $clientTime): array
    {
        $need = fn (string ...$keys) => array_map(fn ($k) => $p[$k] ?? throw new DeskRefused('operation_invalide', 'Opération incomplète.'), $keys);
        switch ($type) {
            case 'identite':
                [$visitor, $photo] = $need('visitor_id', 'photo_b64');
                $bytes = base64_decode((string) $photo, true);

                return $this->desk->saveIdentity($device, $agent, (string) $visitor, (bool) ($p['identity_checked'] ?? true), $bytes === false ? '' : $bytes, isset($p['document_type']) ? (string) $p['document_type'] : null, isset($p['document_number']) ? (string) $p['document_number'] : null);
            case 'valider':
                [$visitor] = $need('visitor_id');

                return $this->desk->validate($device, $agent, (string) $visitor, $p['avis'] ?? null, $clientTime);
            case 'refuser':
                [$visitor, $motif] = $need('visitor_id', 'motif');

                return $this->desk->refuse($device, $agent, (string) $visitor, (string) $motif);
            case 'badge':
                [$visitor, $code] = $need('visitor_id', 'code');

                return $this->desk->assignBadge($device, $agent, (string) $visitor, (string) $code);
            case 'entree':
                [$visitor] = $need('visitor_id');

                return $this->desk->enter($device, $agent, (string) $visitor, (int) ($p['duration_ms'] ?? 0), true, $clientTime);
            case 'controle':
                [$code] = $need('code');
                $verdict = $this->site->control($device, $agent, (string) $code, $this->point($p), $clientTime, isset($p['local_valid']) ? (bool) $p['local_valid'] : null);
                // Le contrôle hors ligne avait jugé le badge valide alors que le serveur le refuse (annulé entre-temps, restitué…) : à signaler.
                if (($p['local_valid'] ?? null) === true && ! $verdict['valid']) {
                    return ['refus' => true, 'reason' => $verdict['reason'] === 'annule' ? 'annule_entre_temps' : (string) $verdict['reason'], 'message' => 'Ce badge a été jugé valide hors ligne mais le serveur le refuse : '.$verdict['message']];
                }

                return $verdict;
            case 'groupe_controle':
                [$group, $visitor] = $need('group_id', 'visitor_id');

                return $this->groups->check($agent, (string) $group, (string) $visitor, (bool) ($p['identity_checked'] ?? false));
            case 'groupe_badge':
                [$group, $visitor, $code] = $need('group_id', 'visitor_id', 'code');

                return $this->groups->deliver($device, $agent, (string) $group, (string) $visitor, (string) $code);
            case 'groupe_depart':
                [$group] = $need('group_id');

                return $this->groups->depart($agent, (string) $group);
            case 'groupe_arrivee':
                [$code] = $need('code');

                return $this->groups->arrive($device, $agent, (string) $code, $clientTime);
            case 'groupe_arrivee_fin':
                [$group] = $need('group_id');

                return $this->groups->finishArrival($agent, (string) $group);
            case 'sortie':
                [$code] = $need('code');

                return $this->site->exit($device, $agent, (string) $code, $this->point($p), $clientTime);
            default:
                [$visitor, $name, $host, $category] = $need('visitor_id', 'name', 'host_id', 'category_id');

                return $this->desk->createUnlisted($device, $agent, (string) $name, $p['organisation'] ?? null, (string) $host, (string) $category, (string) $visitor);
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{type:string,id:string}|null
     */
    private function point(array $p): ?array
    {
        $point = $p['point'] ?? null;

        return is_array($point) && in_array($point['type'] ?? null, ['zone', 'poste'], true) && Str::isUuid((string) ($point['id'] ?? '')) ? ['type' => $point['type'], 'id' => $point['id']] : null;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $payload
     */
    private function quarantine(Device $device, string $operationId, string $type, array $op, ?string $visitorId, array $payload, string $reason): void
    {
        // Seul un code imprimé est conservé : le contenu d'un QR (jeton) ne doit jamais se retrouver, même en partie, dans la table des conflits.
        $badge = isset($payload['code']) && preg_match('/^B-[0-9]{1,9}$/i', (string) $payload['code']) === 1 ? strtoupper((string) $payload['code']) : null;
        $conflict = (string) Str::uuid();
        DB::table('sync_conflicts')->insert(['id' => $conflict, 'operation_id' => $operationId, 'event_id' => $device->event_id, 'device_id' => $device->id, 'agent_id' => $op['agent_id'], 'visitor_id' => $visitorId, 'operation_type' => $type, 'kind' => 'conflit', 'reason' => $reason, 'details' => json_encode(array_filter(['badge_code' => $badge, 'seq' => $op['seq'] ?? null]), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        app(Alerts::class)->raise($device->event_id, 'conflit_sync', 'haute', 'conflit', $conflict, 'Une opération d’une tablette a été mise en quarantaine ('.$reason.').', 'conflit:'.$conflict);
        $this->audit->append('sync.conflit', null, ['operation_id' => $operationId, 'device_id' => $device->id, 'type' => $type, 'raison' => $reason, 'visitor_id' => $visitorId]);
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s.uP');
        } catch (\Throwable) {
            return null;
        }
    }
}
