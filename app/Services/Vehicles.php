<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\PostCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Véhicules (S09). Un véhicule est rattaché à UNE participation de l'activité (plaque normalisée + type). États : annoncé → présent → sorti, ou annulé tant qu'il n'est pas entré.
 * Règles : la même plaque ne sert qu'une participation à la fois (annoncée ou présente) ; un véhicule présent ne change ni de participation ni de plaque (garde SQL) ;
 * l'entrée et la sortie d'un véhicule sont des mouvements (access_events « vehicule_entree » / « vehicule_sortie ») qui ne valent JAMAIS présence de la personne.
 * Les mouvements se font à un poste d'accueil (capacité « accueil » pour l'entrée, « sortie » pour la sortie), en ligne.
 */
final class Vehicles
{
    public const KINDS = ['voiture' => 'Voiture', 'moto' => 'Moto', 'minibus' => 'Minibus', 'camion' => 'Camion', 'autre' => 'Autre'];

    public function __construct(private readonly Audit $audit) {}

    /** Plaque comparable : sans espaces ni tirets, majuscules, lettres et chiffres seulement (2 à 15 caractères). */
    public static function normalize(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($plate)));
    }

    /**
     * Rattache un véhicule à une participation (administrateur : toute participation ; hôte : ses invités).
     *
     * @return string identifiant du véhicule
     *
     * @throws ValidationException
     */
    public function register(User $actor, string $participationId, string $plate, string $kind): string
    {
        $isAdmin = $actor->can('listes.importer');
        if (! $isAdmin && ! $actor->can('listes.mettre_a_jour')) {
            abort(403);
        }
        $normalized = self::normalize($plate);
        if (preg_match('/^[A-Z0-9]{2,15}$/', $normalized) !== 1) {
            throw ValidationException::withMessages(['plate' => 'Plaque invalide : 2 à 15 lettres ou chiffres.']);
        }
        if (! array_key_exists($kind, self::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Type de véhicule inconnu.']);
        }
        $shown = mb_substr(trim($plate), 0, 20);

        return DB::transaction(function () use ($actor, $participationId, $normalized, $shown, $kind, $isAdmin): string {
            if (! $isAdmin) {
                try {
                    app(HostGuests::class)->owned($actor, $participationId, true);
                } catch (DeskRefused) {
                    abort(403);
                }
            }
            $v = DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['participation' => 'Participation introuvable.']);
            if ($v->closed_at !== null || $v->withdrawn_at !== null || $v->holder_id !== null) {
                throw ValidationException::withMessages(['participation' => 'Cette participation est clôturée, retirée ou permanente : aucun véhicule ne peut lui être rattaché.']);
            }
            $holder = DB::table('vehicles')->where('event_id', $v->event_id)->where('plate_normalized', $normalized)->whereIn('status', ['annonce', 'present'])->lockForUpdate()->first();
            if ($holder !== null) {
                throw ValidationException::withMessages(['plate' => $holder->status === 'present'
                    ? 'Ce véhicule est présent sur le site pour une autre participation : il ne peut pas être réaffecté avant sa sortie.'
                    : 'Cette plaque est déjà annoncée pour une autre participation de cette activité.']);
            }
            $id = (string) Str::uuid();
            $now = now()->format('Y-m-d H:i:s.uP');
            try {
                DB::table('vehicles')->insert(['id' => $id, 'event_id' => $v->event_id, 'visitor_id' => $v->id, 'plate' => $shown, 'plate_normalized' => $normalized, 'kind' => $kind, 'status' => 'annonce', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
            } catch (QueryException) {
                throw ValidationException::withMessages(['plate' => 'Cette plaque est déjà utilisée dans cette activité.']);
            }
            $this->audit->append('vehicule.annonce', $actor->id, ['vehicle_id' => $id, 'participation_id' => $v->id, 'event_id' => $v->event_id, 'type' => $kind]);

            return $id;
        });
    }

    /** Annule un véhicule annoncé qui n'est pas encore entré (erreur de saisie, visite annulée). */
    public function cancel(User $actor, string $vehicleId): void
    {
        $isAdmin = $actor->can('listes.importer');
        if (! $isAdmin && ! $actor->can('listes.mettre_a_jour')) {
            abort(403);
        }
        DB::transaction(function () use ($actor, $vehicleId, $isAdmin): void {
            $x = DB::table('vehicles')->where('id', $vehicleId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['vehicle' => 'Véhicule introuvable.']);
            if (! $isAdmin) {
                try {
                    app(HostGuests::class)->owned($actor, $x->visitor_id, true);
                } catch (DeskRefused) {
                    abort(403);
                }
            }
            if ($x->status !== 'annonce') {
                throw ValidationException::withMessages(['vehicle' => 'Seul un véhicule annoncé, pas encore entré, peut être annulé.']);
            }
            DB::table('vehicles')->where('id', $x->id)->update(['status' => 'annule', 'updated_at' => now()->format('Y-m-d H:i:s.uP')]);
            $this->audit->append('vehicule.annule', $actor->id, ['vehicle_id' => $x->id, 'participation_id' => $x->visitor_id, 'event_id' => $x->event_id]);
        });
    }

    /**
     * Recherche par plaque au poste : véhicules annoncés ou présents de l'activité du poste dont la plaque normalisée contient la saisie (3 caractères au moins).
     *
     * @return list<array<string, mixed>>
     */
    public function search(Device $poste, User $agent, string $query): array
    {
        PostCapabilities::require($poste, $agent, 'accueil');
        $needle = self::normalize($query);
        if (strlen($needle) < 3) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';

        return DB::table('vehicles as x')->join('visitors as v', 'v.id', '=', 'x.visitor_id')->where('x.event_id', $poste->event_id)->whereIn('x.status', ['annonce', 'present'])->where('x.plate_normalized', 'like', $like)
            ->orderBy('x.plate_normalized')->limit(20)
            ->get(['x.id', 'x.plate', 'x.kind', 'x.status', 'v.id as visitor_id', 'v.full_name', 'v.status as visitor_status'])
            ->map(fn ($r) => ['id' => $r->id, 'plate' => $r->plate, 'kind' => $r->kind, 'status' => $r->status, 'visitor_id' => $r->visitor_id, 'full_name' => $r->full_name, 'visitor_status' => $r->visitor_status ?? 'attendu'])->values()->all();
    }

    /**
     * Entrée d'un véhicule annoncé. Mouvement distinct de la présence de la personne.
     *
     * @return array<string, mixed>
     */
    public function enter(Device $poste, User $agent, string $vehicleId): array
    {
        PostCapabilities::require($poste, $agent, 'accueil');

        return DB::transaction(function () use ($poste, $agent, $vehicleId): array {
            $x = $this->mine($poste, $vehicleId);
            if ($x->status === 'present') {
                return ['ok' => true, 'already' => true, 'plate' => $x->plate, 'status' => 'present'];
            }
            if ($x->status !== 'annonce') {
                throw new DeskRefused('vehicule_indisponible', 'Ce véhicule est sorti ou annulé : il ne peut plus entrer. Faites annoncer à nouveau la plaque.', 409);
            }
            $v = DB::table('visitors')->where('id', $x->visitor_id)->first();
            if ($v === null || $v->closed_at !== null || $v->withdrawn_at !== null || $v->status === 'refuse') {
                throw new DeskRefused('participation_indisponible', 'La participation de ce véhicule est clôturée, retirée ou refusée : il ne peut pas entrer.', 409);
            }
            $this->movement($poste, $agent, $x, 'vehicule_entree');
            DB::table('vehicles')->where('id', $x->id)->update(['status' => 'present', 'entered_at' => now(), 'entered_by' => $agent->id, 'updated_at' => now()]);
            $this->audit->append('vehicule.entree', $agent->id, ['vehicle_id' => $x->id, 'participation_id' => $x->visitor_id, 'event_id' => $poste->event_id, 'device_id' => $poste->id]);

            return ['ok' => true, 'already' => false, 'plate' => $x->plate, 'status' => 'present'];
        });
    }

    /**
     * Sortie d'un véhicule présent. Libère la plaque.
     *
     * @return array<string, mixed>
     */
    public function exit(Device $poste, User $agent, string $vehicleId): array
    {
        PostCapabilities::require($poste, $agent, 'sortie');

        return DB::transaction(function () use ($poste, $agent, $vehicleId): array {
            $x = $this->mine($poste, $vehicleId);
            if ($x->status === 'sorti') {
                return ['ok' => true, 'already' => true, 'plate' => $x->plate, 'status' => 'sorti'];
            }
            if ($x->status !== 'present') {
                throw new DeskRefused('vehicule_non_present', 'Ce véhicule n’est pas présent sur le site : aucune sortie à enregistrer.', 409);
            }
            $this->movement($poste, $agent, $x, 'vehicule_sortie');
            DB::table('vehicles')->where('id', $x->id)->update(['status' => 'sorti', 'exited_at' => now(), 'exited_by' => $agent->id, 'updated_at' => now()]);
            $this->audit->append('vehicule.sortie', $agent->id, ['vehicle_id' => $x->id, 'participation_id' => $x->visitor_id, 'event_id' => $poste->event_id, 'device_id' => $poste->id]);

            return ['ok' => true, 'already' => false, 'plate' => $x->plate, 'status' => 'sorti'];
        });
    }

    /**
     * Véhicules présents de l'activité (supervision) : plaque, type, participation, depuis quand.
     *
     * @return list<array<string, mixed>>
     */
    public function present(string $eventId): array
    {
        return DB::table('vehicles as x')->join('visitors as v', 'v.id', '=', 'x.visitor_id')->where('x.event_id', $eventId)->where('x.status', 'present')->orderBy('x.entered_at')
            ->get(['x.id', 'x.plate', 'x.kind', 'x.entered_at', 'v.id as visitor_id', 'v.full_name'])
            ->map(fn ($r) => ['id' => $r->id, 'plate' => $r->plate, 'kind' => $r->kind, 'entered_at' => $r->entered_at, 'visitor_id' => $r->visitor_id, 'full_name' => $r->full_name])->values()->all();
    }

    private function mine(Device $poste, string $vehicleId): \stdClass
    {
        return DB::table('vehicles')->where('id', $vehicleId)->where('event_id', $poste->event_id)->lockForUpdate()->first()
            ?? throw new DeskRefused('vehicule_inconnu', 'Véhicule inconnu pour cette activité.', 404);
    }

    private function movement(Device $poste, User $agent, \stdClass $x, string $type): void
    {
        $now = now();
        DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $poste->event_id, 'visitor_id' => $x->visitor_id, 'badge_id' => null, 'actor_id' => $agent->id, 'type' => $type, 'checkpoint' => $poste->station ?? $poste->name, 'occurred_at' => $now,
            'details' => json_encode(['vehicle_id' => $x->id, 'plaque' => $x->plate_normalized, 'poste_id' => $poste->id], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
    }
}
