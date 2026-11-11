<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Audit;
use App\Services\EventAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Enrôlement des tablettes (docs/AUTH_HORS_LIGNE.md, section 3) : code à usage unique, jeton d'appareil haché, révocation. */
final class Devices
{
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_VALID_MINUTES = 15;

    public const MAX_ATTEMPTS_PER_HOUR = 5;

    public function __construct(private readonly Audit $audit) {}

    public function create(?string $eventId, string $name, ?string $station, User $actor, string $pointCode): Device
    {
        if (! array_key_exists($pointCode, PostCapabilities::points())) {
            throw new DeskRefused('point_inconnu', 'Point de contrôle inconnu.', 422);
        }

        return DB::transaction(function () use ($eventId, $name, $station, $actor, $pointCode): Device {
            $device = Device::create(['event_id' => $eventId, 'name' => $name, 'station' => $station, 'point_code' => $pointCode]);
            $this->audit->append('poste.cree', $actor->id, ['device_id' => $device->id, 'event_id' => $eventId, 'point_code' => $pointCode]);

            return $device;
        });
    }

    /**
     * Activités que l'administrateur autorise pour cette tablette. L'activité en cours ne peut pas être retirée ; une activité qui porte encore
     * des badges réservés à cette tablette non plus (la base le refuse). Aucune activité n'est visible par défaut.
     *
     * @param  list<string>  $eventIds
     */
    public function authorize(Device $device, array $eventIds, User $actor): void
    {
        $eventIds = array_values(array_unique($eventIds));
        DB::transaction(function () use ($device, $eventIds, $actor): void {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->event_id !== null && ! in_array($device->event_id, $eventIds, true)) {
                throw new DeskRefused('activite_en_cours', 'L’activité en cours de cette tablette ne peut pas être retirée : choisissez d’abord une autre activité en cours.', 422);
            }
            $known = DB::table('events')->whereIn('id', $eventIds)->pluck('id')->all();
            if (count($known) !== count($eventIds)) {
                throw new DeskRefused('activite_inconnue', 'Activité inconnue.', 422);
            }
            $current = DB::table('device_activities')->where('device_id', $device->id)->pluck('event_id')->all();
            foreach (array_diff($eventIds, $current) as $eventId) {
                DB::table('device_activities')->insert(['id' => (string) Str::uuid(), 'device_id' => $device->id, 'event_id' => $eventId, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (array_diff($current, $eventIds) as $eventId) {
                try {
                    DB::transaction(fn () => DB::table('device_activities')->where('device_id', $device->id)->where('event_id', $eventId)->delete());
                } catch (QueryException) {
                    throw new DeskRefused('activite_utilisee', 'Cette activité garde des badges réservés à cette tablette : libérez-les avant de la retirer.', 422);
                }
            }
            $this->audit->append('poste.activites_modifiees', $actor->id, ['device_id' => $device->id, 'event_ids' => $eventIds]);
        });
    }

    /** Choisit l'activité en cours parmi les activités autorisées ; refusé tant que la tablette est enrôlée (la copie hors ligne serait changée sous ses pieds). */
    public function setCurrent(Device $device, string $eventId, User $actor): void
    {
        DB::transaction(function () use ($device, $eventId, $actor): void {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->token_hash !== null && $device->revoked_at === null) {
                throw new DeskRefused('tablette_enrolee', 'Cette tablette est enrôlée : révoquez-la puis réenrôlez-la pour changer son activité en cours.', 409);
            }
            if (! DB::table('device_activities')->where('device_id', $device->id)->where('event_id', $eventId)->exists()) {
                throw new DeskRefused('activite_non_autorisee', 'Cette activité n’est pas autorisée pour cette tablette.', 422);
            }
            DB::table('devices')->where('id', $device->id)->update(['event_id' => $eventId, 'updated_at' => now()]);
            $this->audit->append('poste.activite_en_cours', $actor->id, ['device_id' => $device->id, 'event_id' => $eventId]);
        });
    }

    /**
     * Activités que cette tablette peut choisir : celles que l'administrateur a autorisées et qui sont encore ouvertes.
     *
     * @return list<array{id:string,name:string,starts_at:string,ends_at:string,current:bool}>
     */
    public function choices(Device $device): array
    {
        return DB::table('device_activities as a')->join('events as e', 'e.id', '=', 'a.event_id')
            ->where('a.device_id', $device->id)->whereNull('e.anonymized_at')
            ->where(fn ($q) => $q->whereNull('e.status')->orWhereNotIn('e.status', ['clos', 'archive']))
            ->orderBy('e.starts_at')->orderBy('e.name')->get(['e.id', 'e.name', 'e.starts_at', 'e.ends_at'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'starts_at' => Carbon::parse($e->starts_at)->toIso8601String(), 'ends_at' => Carbon::parse($e->ends_at)->toIso8601String(), 'current' => $e->id === $device->event_id])->all();
    }

    /**
     * L'agent change l'activité du jour de sa tablette, parmi les activités autorisées et ouvertes, ou revient au mode site (`null`). Le client envoie d'abord toute sa file ; les opérations
     * saisies entre-temps pour l'ancienne activité sont refusées par le serveur (visiteur inconnu) : jamais appliquées à la mauvaise activité.
     */
    public function switchActivity(Device $device, ?string $eventId, User $agent): void
    {
        DB::transaction(function () use ($device, $eventId, $agent): void {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($device->revoked_at !== null || $device->token_hash === null) {
                throw new DeskRefused('appareil_non_enrole', 'Cette tablette n’est pas enrôlée.', 409);
            }
            // « Aucune activité » (null) : retour au mode site, toujours permis (la tablette contrôle alors le personnel permanent).
            if ($eventId !== null) {
                if (! DB::table('device_activities')->where('device_id', $device->id)->where('event_id', $eventId)->exists()) {
                    throw new DeskRefused('activite_non_autorisee', 'Cette activité n’est pas autorisée pour cette tablette : demandez à l’administrateur.', 403);
                }
                EventAvailability::requireOpen($eventId);
            }
            if ($device->event_id === $eventId) {
                return;
            }
            try {
                DB::transaction(fn () => DB::table('devices')->where('id', $device->id)->update(['event_id' => $eventId, 'updated_at' => now()]));
            } catch (QueryException) {
                throw new DeskRefused('badges_reserves', 'Des badges de l’activité en cours sont réservés à cette tablette : libérez-les avant de la quitter.', 409);
            }
            $this->audit->append($eventId === null ? 'poste.mode_site' : 'poste.activite_changee', $agent->id, ['device_id' => $device->id, 'de' => $device->event_id, 'vers' => $eventId]);
        });
    }

    /**
     * Une tablette sans activité en cours en reçoit une quand l'administrateur n'en a autorisé qu'une seule. Sinon (aucune activité autorisée, ou plusieurs sans
     * choix), elle s'enrôle SANS activité : mode site (contrôle du personnel permanent) ; l'agent choisit l'activité du jour sur la tablette, parmi celles autorisées.
     */
    private function ensureCurrentActivity(Device $device): Device
    {
        if ($device->event_id !== null) {
            return $device;
        }
        $authorized = DB::table('device_activities')->where('device_id', $device->id)->pluck('event_id')->all();
        if (count($authorized) !== 1) {
            return $device;
        }
        DB::table('devices')->where('id', $device->id)->update(['event_id' => $authorized[0], 'updated_at' => now()]);

        return $device->refresh();
    }

    /** Génère un code à afficher une seule fois ; seul son hachage est conservé. */
    public function issueCode(Device $device, User $actor): string
    {
        if ($device->point_code === null) {
            throw new DeskRefused('poste_non_identifie', 'Précisez d’abord le point de contrôle de ce poste : aucune tablette n’est enrôlée sans point identifié.', 422);
        }
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        DB::transaction(function () use ($device, $code, $actor): void {
            $device = $this->ensureCurrentActivity($device);
            if ($device->event_id !== null) {
                EventAvailability::requireOpen($device->event_id);
            }
            DB::table('devices')->where('id', $device->id)->update(['enrollment_hash' => self::hash($code), 'enrollment_expires_at' => now()->addMinutes(self::CODE_VALID_MINUTES), 'updated_at' => now()]);
            $this->audit->append('poste.code_emis', $actor->id, ['device_id' => $device->id]);
        });

        return implode('-', str_split($code, 4));
    }

    /**
     * Échange un code contre un jeton d'appareil. Le jeton et le secret d'appareil ne sont renvoyés qu'une fois.
     *
     * @return array{device_token:string,device_secret:string,poste:array<string,mixed>}
     */
    public function enrol(string $code, User $agent): array
    {
        $key = 'poste-enrol:'.$agent->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_HOUR)) {
            $this->audit->append('poste.enrolement_refuse', $agent->id, ['raison' => 'trop_d_essais']);
            throw new DeskRefused('trop_d_essais', 'Trop d’essais : attendez avant de réessayer ou demandez un nouveau code.', 429);
        }

        return DB::transaction(function () use ($code, $agent, $key): array {
            $device = Device::query()->where('enrollment_hash', self::hash($code))->where('enrollment_expires_at', '>', now())->lockForUpdate()->first();
            if ($device === null) {
                RateLimiter::hit($key, 3600);
                $this->audit->append('poste.enrolement_refuse', $agent->id, ['raison' => 'code_invalide']);
                throw new DeskRefused('code_invalide', 'Code d’enrôlement invalide ou expiré : demandez-en un nouveau à l’administrateur.', 422);
            }
            $device = $this->ensureCurrentActivity($device);
            if ($device->event_id !== null) {
                EventAvailability::requireOpen($device->event_id);
            }
            if ($device->point_code === null) {
                throw new DeskRefused('poste_non_identifie', 'Ce poste n’a pas de point de contrôle identifié : demandez à l’administrateur de le préciser.', 422);
            }
            $token = bin2hex(random_bytes(32));
            $secret = base64_encode(random_bytes(32));
            DB::table('devices')->where('id', $device->id)->update(['sync_seq' => 0, 'token_hash' => hash('sha256', $token), 'enrollment_hash' => null, 'enrollment_expires_at' => null, 'enrolled_at' => now(), 'revoked_at' => null, 'wipe_acknowledged_at' => null, 'updated_at' => now()]);
            RateLimiter::clear($key);
            $this->audit->append('poste.enrole', $agent->id, ['device_id' => $device->id, 'event_id' => $device->event_id]);

            return ['device_token' => $token, 'device_secret' => $secret, 'poste' => ['id' => $device->id, 'name' => $device->name, 'station' => $device->station, 'event_id' => $device->event_id, 'event_name' => $device->event_id === null ? null : (string) DB::table('events')->where('id', $device->event_id)->value('name')]];
        });
    }

    public function revoke(Device $device, User $actor): void
    {
        DB::transaction(function () use ($device, $actor): void {
            DB::table('devices')->where('id', $device->id)->update(['revoked_at' => now(), 'enrollment_hash' => null, 'enrollment_expires_at' => null, 'updated_at' => now()]);
            $this->audit->append('poste.revoque', $actor->id, ['device_id' => $device->id]);
        });
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))));
    }

    public static function newOperationId(): string
    {
        return (string) Str::uuid();
    }
}
