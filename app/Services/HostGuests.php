<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visitor;
use App\Services\Imports\ListAnalysis;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HostGuests
{
    public function owned(User $actor, string $id, bool $lock = false): Visitor
    {
        if ($lock) {
            $host = DB::table('hosts')->where('user_id', $actor->id)->whereExists(fn ($q) => $q->selectRaw('1')->from('visitors')->where('visitors.id', $id)->whereColumn('visitors.host_id', 'hosts.id'))->lockForUpdate()->first(['id']);
            if (! $host) {
                throw new DeskRefused('invite_interdit', 'Cet invité ne fait pas partie de votre liste.', 403);
            }
        }
        $query = Visitor::query()->whereHas('host', fn ($h) => $h->where('user_id', $actor->id))->whereNull('withdrawn_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($id) ?? throw new DeskRefused('invite_interdit', 'Cet invité ne fait pas partie de votre liste.', 403);
    }

    public function editable(Visitor $v, bool $strict = false): void
    {
        if (DB::table('access_events')->where('visitor_id', $v->id)->where(fn ($q) => $strict ? $q : $q->where('type', 'entree'))->exists() || ($strict && (DB::table('identity_checks')->where('visitor_id', $v->id)->exists() || DB::table('badge_assignments')->where('visitor_id', $v->id)->exists())) || in_array($v->status, ['present', 'sorti'], true)) {
            throw new DeskRefused('invite_deja_arrive', 'Cette fiche a déjà servi à un contrôle : elle ne peut plus être modifiée.', 409);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{id:string,homonym:bool}
     */
    public function save(User $actor, array $data, ?string $id = null, bool $administration = false): array
    {
        if ($administration) {
            abort_unless($actor->can('listes.importer'), 403);
        }
        $original = $id ? ($administration ? Visitor::whereNull('withdrawn_at')->findOrFail($id) : $this->owned($actor, $id)) : null;
        $hostId = $administration ? ($data['host_id'] ?? null) : ($original ? $original->host_id : $data['host_id']);
        $hostQuery = DB::table('hosts')->where('id', $hostId);
        if (! $administration) {
            $hostQuery->where('user_id', $actor->id);
        }
        $host = $hostQuery->first();
        abort_unless($administration || $host !== null, 403);
        $eventId = $administration ? ($original->event_id ?? $data['event_id']) : $host->event_id;
        if ($host && $host->event_id !== $eventId) {
            throw ValidationException::withMessages(['host_id' => 'Cet hôte appartient à un autre événement.']);
        }
        if ($hostId && ! $host) {
            throw ValidationException::withMessages(['host_id' => 'Hôte introuvable.']);
        }

        return DB::transaction(function () use ($actor, $data, $id, $host, $administration, $eventId) {
            // Même verrou que les imports L3 : pas de doublons créés par deux saisies/imports concurrents.
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?,3))', [$eventId]);
            if (! $administration) {
                $host = DB::table('hosts')->where('id', $host->id)->where('user_id', $actor->id)->lockForUpdate()->first();
                abort_unless($host !== null, 403);
            }
            $v = $id ? ($administration ? Visitor::whereNull('withdrawn_at')->lockForUpdate()->findOrFail($id) : $this->owned($actor, $id, true)) : null;
            if ($v) {
                $this->editable($v, $administration);
            }
            $clean = app(ListAnalysis::class);
            $name = $clean->clean($data['full_name']);
            $org = $clean->clean($data['organisation'] ?? '');
            if ($name === '' || mb_strlen($name) > 160 || mb_strlen($org) > 160) {
                throw ValidationException::withMessages(['full_name' => 'Indiquez un nom de 1 à 160 caractères et une organisation de 160 caractères au plus.']);
            }
            $category = DB::table('categories')->where('event_id', $eventId)->where('id', $data['category_id'])->first();
            if (! $category || (! $administration && $category->id !== $v?->category_id && DB::selectOne('SELECT public.ikoma_normalize(?) AS n', [$category->name])->n !== 'visiteur')) {
                throw ValidationException::withMessages(['category_id' => 'Seule la catégorie Visiteur est autorisée.']);
            }
            if ($v && $v->category_id !== $category->id && ($v->group_id !== null || DB::table('badges')->where('printed_for_visitor_id', $v->id)->exists() || DB::table('badge_assignments')->where('visitor_id', $id)->whereNull('returned_at')->whereNull('cancelled_at')->exists())) {
                throw ValidationException::withMessages(['category_id' => 'La catégorie est liée à un groupe ou à un badge déjà remis. Contactez l’administrateur.']);
            }
            $from = Carbon::parse($data['valid_from']);
            $until = Carbon::parse($data['valid_until']);
            if ($until->lt($from)) {
                throw ValidationException::withMessages(['valid_until' => 'La fin de validité précède le début.']);
            }
            $same = DB::table('visitors as v')->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')->where('v.event_id', $eventId)->whereNull('v.withdrawn_at')->whereRaw('public.ikoma_normalize(v.full_name)=public.ikoma_normalize(?)', [$name]);
            if ($id) {
                $same->where('v.id', '<>', $id);
            }
            $duplicate = (clone $same)->whereRaw("public.ikoma_normalize(coalesce(v.organisation,''))=public.ikoma_normalize(?)", [$org])->whereRaw("public.ikoma_normalize(coalesce(h.name,''))=public.ikoma_normalize(?)", [$host->name ?? ''])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['full_name' => 'Doublon : une fiche de même nom, organisation et hôte existe déjà.']);
            }
            $homonym = $same->exists();
            $values = ['full_name' => $name, 'organisation' => $org ?: null, 'category_id' => $category->id, 'valid_from' => $from, 'valid_until' => $until];
            if ($administration) {
                $values['host_id'] = $host?->id;
                $values['position'] = $clean->clean($data['position'] ?? '') ?: null;
            }
            $fields = [];
            foreach ($values as $key => $value) {
                if (! $v || (in_array($key, ['valid_from', 'valid_until'], true) ? ! Carbon::parse($v->getRawOriginal($key))->equalTo($value) : $value !== $v->$key)) {
                    $fields[] = $key;
                }
            }
            $at = now()->format('Y-m-d H:i:s.uP');
            $visitorId = $id ?? (string) Str::uuid();
            if (! $v) {
                DB::table('visitors')->insert(['id' => $visitorId, 'event_id' => $eventId, 'host_id' => $host?->id, ...$values, 'source' => 'listé', 'status' => 'attendu', 'created_at' => $at, 'updated_at' => $at]);
            } elseif ($fields) {
                DB::table('visitors')->where('id', $id)->update([...$values, 'updated_at' => $at]);
            }
            if (! $v || $fields) {
                DB::table('events')->where('id', $eventId)->increment('list_revision');
                app(Audit::class)->append($v ? 'liste.invite_modifie' : 'liste.invite_ajoute', $actor->id, ['visitor_id' => $visitorId, 'host_id' => $host?->id, 'event_id' => $eventId, 'fields' => $fields]);
            }

            return ['id' => $visitorId, 'homonym' => $homonym];
        });
    }

    public function withdraw(User $actor, string $id, bool $administration = false): void
    {
        DB::transaction(function () use ($actor, $id, $administration) {
            abort_unless(! $administration || $actor->can('listes.importer'), 403);
            $v = $administration ? Visitor::whereNull('withdrawn_at')->lockForUpdate()->findOrFail($id) : $this->owned($actor, $id, true);
            $this->editable($v, $administration);
            // Retrait logique : préserve les références des contrôles et journaux en ajout seul.
            DB::table('visitors')->where('id', $id)->update(['withdrawn_at' => now(), 'updated_at' => now()]);
            DB::table('events')->where('id', $v->event_id)->increment('list_revision');
            app(Alerts::class)->resolve('visiteur', $id, 'non_liste_attente');
            app(Audit::class)->append('liste.invite_retire', $actor->id, ['visitor_id' => $id, 'host_id' => $v->host_id, 'event_id' => $v->event_id]);
        });
    }
}
