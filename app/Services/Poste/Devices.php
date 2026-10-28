<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Audit;
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

    public function create(string $eventId, string $name, ?string $station, User $actor): Device
    {
        return DB::transaction(function () use ($eventId, $name, $station, $actor): Device {
            $device = Device::create(['event_id' => $eventId, 'name' => $name, 'station' => $station]);
            $this->audit->append('poste.cree', $actor->id, ['device_id' => $device->id, 'event_id' => $eventId]);

            return $device;
        });
    }

    /** Génère un code à afficher une seule fois ; seul son hachage est conservé. */
    public function issueCode(Device $device, User $actor): string
    {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        DB::transaction(function () use ($device, $code, $actor): void {
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
            $token = bin2hex(random_bytes(32));
            $secret = base64_encode(random_bytes(32));
            DB::table('devices')->where('id', $device->id)->update(['token_hash' => hash('sha256', $token), 'enrollment_hash' => null, 'enrollment_expires_at' => null, 'enrolled_at' => now(), 'revoked_at' => null, 'wipe_acknowledged_at' => null, 'updated_at' => now()]);
            RateLimiter::clear($key);
            $this->audit->append('poste.enrole', $agent->id, ['device_id' => $device->id, 'event_id' => $device->event_id]);

            return ['device_token' => $token, 'device_secret' => $secret, 'poste' => ['id' => $device->id, 'name' => $device->name, 'station' => $device->station, 'event_id' => $device->event_id, 'event_name' => (string) DB::table('events')->where('id', $device->event_id)->value('name')]];
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
