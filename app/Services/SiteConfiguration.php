<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\PostCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Configuration du site (V1, PR 1) : postes de contrôle permanents, capacités, affectation des tablettes. Contrat : docs/ailct/V1-INVARIANTS-SITE-ET-CONTROLE.md.
 * Droit unique : `postes.gerer` (administrateur). Toute modification est tracée. La concurrence est maîtrisée côté serveur : verrou de la ligne, version du poste
 * contrôlée (refus lisible si la fiche a changé), une seule affectation ouverte par tablette garantie par la base.
 * Aucun poste n'est codé en dur ici : le catalogue de capacités est celui qui est réellement implémenté (PostCapabilities::CATALOGUE).
 */
final class SiteConfiguration
{
    public function __construct(private readonly Audit $audit) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $site = DB::table('sites')->orderBy('created_at')->orderBy('id')->first(['id', 'name']);
        $tablets = DB::table('devices as d')->leftJoin('events as e', 'e.id', '=', 'd.event_id')->orderBy('d.name')
            ->get(['d.id', 'd.name', 'd.station', 'd.checkpoint_id', 'd.token_hash', 'd.revoked_at', 'd.last_synced_at', 'e.name as event_name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'station' => $d->station, 'checkpoint_id' => $d->checkpoint_id, 'state' => $d->revoked_at ? 'revoque' : ($d->token_hash ? 'enrole' : 'non_enrole'),
                'last_synced_at' => $d->last_synced_at, 'event_name' => $d->event_name ?? 'Aucune activité en cours']);
        $siteMappings = DB::table('checkpoint_site_zones as c')->join('zones as z', 'z.id', '=', 'c.zone_id')->orderBy('z.name')->get(['c.id', 'c.checkpoint_id', 'c.zone_id', 'z.name'])->groupBy('checkpoint_id');
        $checkpoints = DB::table('checkpoints')->orderBy('code')->get()->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'function_label' => $p->function_label, 'capabilities' => PostCapabilities::normalize(json_decode((string) $p->capabilities, true)),
            'direction_required' => (bool) $p->direction_required, 'active' => (bool) $p->active, 'version' => (int) $p->version,
            'tablets' => $tablets->where('checkpoint_id', $p->id)->where('state', '!=', 'revoque')->values()->all(),
            'site_zones' => ($siteMappings[$p->id] ?? collect())->map(fn ($z) => ['mapping_id' => $z->id, 'zone_id' => $z->zone_id, 'name' => $z->name])->values()->all(),
        ])->all();

        return [
            'site' => $site === null ? null : ['id' => $site->id, 'name' => $site->name],
            'checkpoints' => $checkpoints,
            'catalogue' => PostCapabilities::CATALOGUE,
            'unassigned' => $tablets->whereNull('checkpoint_id')->where('state', '!=', 'revoque')->values()->all(),
            'site_zones' => DB::table('zones')->orderBy('name')->get(['id', 'name', 'est_accueil'])->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'est_accueil' => (bool) $z->est_accueil])->all(),
            'history' => DB::table('device_assignments as a')->join('devices as d', 'd.id', '=', 'a.device_id')->leftJoin('checkpoints as p', 'p.id', '=', 'a.checkpoint_id')->leftJoin('users as u', 'u.id', '=', 'a.assigned_by')
                ->orderByDesc('a.assigned_at')->orderByDesc('a.id')->limit(25)->get(['a.id', 'd.name as device', 'p.name as checkpoint', 'a.assigned_at', 'a.ended_at', 'a.reason', 'u.name as by']),
        ];
    }

    /** Crée une zone du site (permanente, sans événement). */
    public function createSiteZone(string $name, User $actor, bool $reception = false): string
    {
        $this->authorize($actor);
        $name = (string) $this->text($name, 120, 'name', true);

        return DB::transaction(function () use ($name, $actor, $reception): string {
            DB::statement('SELECT pg_advisory_xact_lock(731904)');
            $siteId = (string) DB::table('sites')->orderBy('created_at')->orderBy('id')->value('id');
            if (DB::table('zones')->where('site_id', $siteId)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
                throw ValidationException::withMessages(['name' => 'Une zone du site porte déjà ce nom.']);
            }
            $id = (string) Str::uuid();
            DB::table('zones')->insert(['id' => $id, 'site_id' => $siteId, 'name' => $name, 'est_accueil' => $reception, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('site.zone_creee', $actor->id, ['zone_id' => $id]);

            return $id;
        });
    }

    /** Associe une zone du site à un poste de contrôle (le poste garde cette zone). */
    public function mapSiteZone(string $checkpointId, string $zoneId, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($checkpointId, $zoneId, $actor): void {
            if (! DB::table('checkpoints')->where('id', $checkpointId)->where('active', true)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['checkpoint_id' => 'Poste inconnu ou désactivé.']);
            }
            if (! DB::table('zones')->where('id', $zoneId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Zone du site inconnue.']);
            }
            if (DB::table('checkpoint_site_zones')->where('checkpoint_id', $checkpointId)->where('zone_id', $zoneId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Cette zone est déjà gardée par ce poste.']);
            }
            $id = (string) Str::uuid();
            DB::table('checkpoint_site_zones')->insert(['id' => $id, 'checkpoint_id' => $checkpointId, 'zone_id' => $zoneId, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('site.zone_associee_poste', $actor->id, ['correspondance_id' => $id, 'checkpoint_id' => $checkpointId, 'zone_id' => $zoneId]);
        });
    }

    public function unmapSiteZone(string $mappingId, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($mappingId, $actor): void {
            $row = DB::table('checkpoint_site_zones')->where('id', $mappingId)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            DB::table('checkpoint_site_zones')->where('id', $mappingId)->delete();
            $this->audit->append('site.zone_dissociee_poste', $actor->id, ['correspondance_id' => $mappingId, 'checkpoint_id' => $row->checkpoint_id, 'zone_id' => $row->zone_id]);
        });
    }

    public function renameSiteZone(string $zoneId, string $name, User $actor): void
    {
        $this->authorize($actor);
        $name = (string) $this->text($name, 120, 'name', true);
        DB::transaction(function () use ($zoneId, $name, $actor): void {
            DB::statement('SELECT pg_advisory_xact_lock(731904)');
            $zone = DB::table('zones')->where('id', $zoneId)->lockForUpdate()->first(['id', 'site_id']);
            abort_if($zone === null, 404);
            if (DB::table('zones')->where('site_id', $zone->site_id)->where('id', '<>', $zoneId)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
                throw ValidationException::withMessages(['name' => 'Une zone du site porte déjà ce nom.']);
            }
            DB::table('zones')->where('id', $zoneId)->update(['name' => $name, 'updated_at' => now()]);
            $this->audit->append('site.zone_renommee', $actor->id, ['zone_id' => $zoneId]);
        });
    }

    /** Zone d'accueil du site : seule zone accessible aux visiteurs non listés. Au moins une doit rester, tant qu'une zone du site existe. */
    public function setSiteZoneReception(string $zoneId, bool $reception, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($zoneId, $reception, $actor): void {
            DB::statement('SELECT pg_advisory_xact_lock(731904)');
            $zone = DB::table('zones')->where('id', $zoneId)->lockForUpdate()->first(['id', 'est_accueil']);
            abort_if($zone === null, 404);
            if ((bool) $zone->est_accueil === $reception) {
                return;
            }
            if (! $reception && ! DB::table('zones')->where('id', '<>', $zoneId)->where('est_accueil', true)->exists()) {
                throw ValidationException::withMessages(['est_accueil' => 'La zone d’accueil obligatoire doit être conservée : désignez d’abord une autre zone d’accueil.']);
            }
            DB::table('zones')->where('id', $zoneId)->update(['est_accueil' => $reception, 'updated_at' => now()]);
            $this->audit->append('site.zone_accueil', $actor->id, ['zone_id' => $zoneId, 'est_accueil' => $reception]);
        });
    }

    public function renameSite(string $name, User $actor): void
    {
        $this->authorize($actor);
        $name = $this->text($name, 160, 'name', true);
        DB::transaction(function () use ($name, $actor): void {
            $site = DB::table('sites')->orderBy('created_at')->orderBy('id')->lockForUpdate()->first(['id']);
            abort_if($site === null, 404);
            DB::table('sites')->where('id', $site->id)->update(['name' => $name, 'updated_at' => now()]);
            $this->audit->append('site.renomme', $actor->id, ['site_id' => $site->id]);
        });
    }

    /**
     * @param  array{code?: mixed, name?: mixed, function_label?: mixed, capabilities?: mixed, direction_required?: mixed}  $data
     * @return string identifiant du poste
     */
    public function createCheckpoint(array $data, User $actor): string
    {
        $this->authorize($actor);
        $code = (string) ($data['code'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_]{1,19}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Code de 2 à 20 caractères : minuscules, chiffres et tiret bas, commençant par une lettre.']);
        }
        if ($code === PostCapabilities::NO_POST) {
            throw ValidationException::withMessages(['code' => 'Ce code est réservé aux tablettes sans poste.']);
        }
        [$capabilities, $direction] = $this->configuration($data);

        return DB::transaction(function () use ($data, $code, $capabilities, $direction, $actor): string {
            if (DB::table('checkpoints')->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Un poste porte déjà ce code.']);
            }
            $site = DB::table('sites')->orderBy('created_at')->orderBy('id')->first(['id']);
            abort_if($site === null, 404);
            $id = (string) Str::uuid();
            DB::table('checkpoints')->insert(['id' => $id, 'site_id' => $site->id, 'code' => $code, 'name' => $this->text($data['name'] ?? '', 120, 'name', true), 'function_label' => $this->text($data['function_label'] ?? '', 160, 'function_label', false),
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'direction_required' => $direction, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('poste.cree', $actor->id, ['checkpoint_id' => $id, 'code' => $code, 'capacites' => $capabilities, 'sens_obligatoire' => $direction]);

            return $id;
        });
    }

    /**
     * Modifie la configuration d'un poste. `$version` est la version lue par l'administrateur : si le poste a changé entre-temps, la modification est refusée.
     *
     * @param  array{name?: mixed, function_label?: mixed, capabilities?: mixed, direction_required?: mixed}  $data
     */
    public function updateCheckpoint(string $id, array $data, int $version, User $actor): void
    {
        $this->authorize($actor);
        [$capabilities, $direction] = $this->configuration($data);
        DB::transaction(function () use ($id, $data, $version, $capabilities, $direction, $actor): void {
            $row = $this->locked($id, $version);
            DB::table('checkpoints')->where('id', $id)->update(['name' => $this->text($data['name'] ?? '', 120, 'name', true), 'function_label' => $this->text($data['function_label'] ?? '', 160, 'function_label', false),
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'direction_required' => $direction]);
            $this->audit->append('poste.configure', $actor->id, ['checkpoint_id' => $id, 'code' => $row->code,
                'avant' => ['capacites' => PostCapabilities::normalize(json_decode((string) $row->capabilities, true)), 'sens_obligatoire' => (bool) $row->direction_required],
                'apres' => ['capacites' => $capabilities, 'sens_obligatoire' => $direction]]);
        });
    }

    /** Désactive ou réactive un poste (jamais supprimé). Un poste désactivé n'a plus aucune capacité ; ses tablettes restent affectées. */
    public function setActive(string $id, bool $active, int $version, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($id, $active, $version, $actor): void {
            $row = $this->locked($id, $version);
            if ((bool) $row->active === $active) {
                return;
            }
            DB::table('checkpoints')->where('id', $id)->update(['active' => $active]);
            $this->audit->append($active ? 'poste.reactive' : 'poste.desactive', $actor->id, ['checkpoint_id' => $id, 'code' => $row->code]);
        });
    }

    /** Affecte une tablette à un poste actif (ou la retire de son poste). Plusieurs tablettes par poste sont permises ; l'historique est conservé. */
    public function assign(string $deviceId, ?string $checkpointId, ?string $reason, User $actor): void
    {
        $this->authorize($actor);
        $reason = $this->text($reason ?? '', 200, 'reason', false);
        DB::transaction(function () use ($deviceId, $checkpointId, $reason, $actor): void {
            $device = DB::table('devices')->where('id', $deviceId)->lockForUpdate()->first(['id', 'checkpoint_id', 'token_hash', 'revoked_at']);
            abort_if($device === null, 404);
            if ($device->revoked_at !== null) {
                throw ValidationException::withMessages(['checkpoint_id' => 'Cette tablette est révoquée : elle ne peut plus être affectée.']);
            }
            if ($checkpointId !== null) {
                $target = DB::table('checkpoints')->where('id', $checkpointId)->lockForUpdate()->first(['id', 'active']);
                if ($target === null || ! $target->active) {
                    throw ValidationException::withMessages(['checkpoint_id' => 'Poste inconnu ou désactivé.']);
                }
            } elseif ($device->token_hash !== null) {
                throw ValidationException::withMessages(['checkpoint_id' => 'Une tablette enrôlée doit rester affectée à un poste dans cette version.']);
            }
            if ($device->checkpoint_id === $checkpointId) {
                return;
            }
            DB::table('devices')->where('id', $deviceId)->update(['checkpoint_id' => $checkpointId, 'updated_at' => now()]);
            // Le déclencheur a clos l'affectation précédente et ouvert la nouvelle : on y inscrit l'auteur et le motif.
            DB::table('device_assignments')->where('device_id', $deviceId)->whereNull('ended_at')->update(['assigned_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]);
            $this->audit->append('tablette.affectee', $actor->id, ['device_id' => $deviceId, 'de' => $device->checkpoint_id, 'vers' => $checkpointId]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<string>, 1: bool}
     */
    private function configuration(array $data): array
    {
        $raw = $data['capabilities'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        foreach ($raw as $capability) {
            if (! is_string($capability) || ! array_key_exists($capability, PostCapabilities::CATALOGUE)) {
                throw ValidationException::withMessages(['capabilities' => 'Capacité non implémentée : seules les capacités du catalogue peuvent être activées.']);
            }
        }
        $capabilities = PostCapabilities::normalize($raw);
        $direction = filter_var($data['direction_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($direction && ! in_array('scan', $capabilities, true)) {
            throw ValidationException::withMessages(['direction_required' => 'Le choix Entrée / Sortie obligatoire n’a de sens qu’avec le contrôle d’un badge.']);
        }

        return [$capabilities, $direction];
    }

    private function locked(string $id, int $version): object
    {
        $row = DB::table('checkpoints')->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null, 404);
        if ((int) $row->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Ce poste a été modifié entre-temps par un autre administrateur : rechargez la page avant de réessayer.']);
        }

        return $row;
    }

    private function text(mixed $value, int $max, string $field, bool $required): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => 'Ce champ est obligatoire.']);
            }

            return null;
        }
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            throw ValidationException::withMessages([$field => 'Valeur invalide ou trop longue.']);
        }

        return $value;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('postes.gerer'), 403);
    }
}
