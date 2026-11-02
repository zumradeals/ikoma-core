<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registre des titulaires permanents : référence d'identité, indépendante de tout événement.
 *
 * - L'identifiant imprimable est émis une seule fois (séquence PostgreSQL, jamais recyclée), jamais modifié, jamais réattribué.
 *   Il est distinct de l'UUID, du code de badge B-nnn et du jeton QR. Son format (préfixe, largeur, clé de contrôle) est un réglage.
 * - Un titulaire ne se supprime pas : il est actif ou clos (clôture irréversible, motif obligatoire). Un déclencheur en base l'impose.
 * - Aucune règle d'accès, d'entrée, de sortie ou de contrôle ne lit ce registre (lot A06). Aucune durée de conservation n'est appliquée ici.
 * - Le journal d'audit ne contient ni nom ni organisation : seulement l'identifiant et les noms de champs modifiés.
 */
final class PermanentHolders
{
    public const FIELDS = ['full_name', 'organisation', 'position', 'institutional_category', 'engagement_starts_on', 'engagement_ends_on'];

    public const DEFAULT_PREFIX = 'ID';

    public const DEFAULT_WIDTH = 6;

    /** @return array{prefix: string, width: int, check: bool} */
    public function format(): array
    {
        $setting = fn (string $key, string $default): string => (string) (DB::table('settings')->where('key', $key)->value('value') ?? $default);
        $prefix = $setting('titulaire_identifiant_prefixe', self::DEFAULT_PREFIX);
        $width = (int) $setting('titulaire_identifiant_largeur', (string) self::DEFAULT_WIDTH);

        return [
            'prefix' => preg_match('/\A[A-Z0-9]{0,8}\z/', $prefix) === 1 ? $prefix : self::DEFAULT_PREFIX,
            'width' => $width >= 4 && $width <= 12 ? $width : self::DEFAULT_WIDTH,
            'check' => $setting('titulaire_identifiant_cle', '1') !== '0',
        ];
    }

    /** @param array{prefix: string, width: int, check: bool} $data */
    public function saveFormat(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor): void {
            foreach (['titulaire_identifiant_prefixe' => $data['prefix'], 'titulaire_identifiant_largeur' => (string) $data['width'], 'titulaire_identifiant_cle' => $data['check'] ? '1' : '0'] as $key => $value) {
                DB::table('settings')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
            }
            // Les identifiants déjà émis ne changent jamais : seul le format des prochains est concerné.
            app(Audit::class)->append('titulaire.format_identifiant_modifie', $actor->id, ['prefixe' => $data['prefix'], 'largeur' => $data['width'], 'cle' => $data['check']]);
        });
    }

    /** Clé de contrôle de Luhn sur les chiffres du numéro de séquence (détecte une faute de saisie ou une transposition). */
    public static function checkDigit(int $sequence): int
    {
        $sum = 0;
        foreach (array_reverse(str_split((string) $sequence)) as $i => $digit) {
            $n = (int) $digit * ($i % 2 === 0 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return (10 - $sum % 10) % 10;
    }

    public function formatIdentifier(int $sequence): string
    {
        $format = $this->format();
        $number = str_pad((string) $sequence, $format['width'], '0', STR_PAD_LEFT);

        return ($format['prefix'] !== '' ? $format['prefix'].'-' : '').$number.($format['check'] ? '-'.self::checkDigit($sequence) : '');
    }

    /** Vrai si la clé de contrôle d'un identifiant au format courant est correcte. */
    public function verify(string $identifier): bool
    {
        if (! preg_match('/(?:\A|-)(\d+)-(\d)\z/', $identifier, $m)) {
            return false;
        }

        return self::checkDigit((int) $m[1]) === (int) $m[2];
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function paginate(?string $query, string $status, ?string $category, int $perPage = 25): LengthAwarePaginator
    {
        $q = DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')
            ->select('h.*', 'c.label as category_label', 'c.color as category_color', 'c.symbol as category_symbol');
        if (in_array($status, ['actif', 'clos'], true)) {
            $q->where('h.status', $status);
        }
        if ($category !== null && $category !== '') {
            $q->where('h.institutional_category', $category);
        }
        $term = trim((string) $query);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->whereRaw('public.ikoma_normalize(h.full_name) LIKE public.ikoma_normalize(?)', [$like])
                ->orWhereRaw("public.ikoma_normalize(coalesce(h.organisation, '')) LIKE public.ikoma_normalize(?)", [$like])
                ->orWhere('h.identifier', 'ilike', $like));
        }

        return $q->orderByRaw("h.status = 'actif' DESC")->orderByRaw('public.ikoma_normalize(h.full_name)')->orderBy('h.id')->paginate($perPage)->withQueryString();
    }

    public function find(string $id): ?object
    {
        return DB::table('permanent_holders')->where('id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, bool $confirmHomonym = false): string
    {
        $clean = $this->clean($data);

        return $this->guardRace(fn () => DB::transaction(function () use ($clean, $actor, $confirmHomonym): string {
            $this->preventDuplicates($clean, null, $confirmHomonym);
            $sequence = (int) DB::selectOne("SELECT nextval('permanent_holder_identifier_seq') AS n")->n;
            $id = (string) Str::uuid();
            $identifier = $this->formatIdentifier($sequence);
            DB::table('permanent_holders')->insert([
                'id' => $id, 'identifier' => $identifier, 'identifier_seq' => $sequence, ...$clean, 'status' => 'actif',
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(Audit::class)->append('titulaire.cree', $actor->id, ['holder_id' => $id, 'identifiant' => $identifier]);

            return $id;
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> noms des champs modifiés
     */
    public function update(string $id, array $data, User $actor, bool $confirmHomonym = false): array
    {
        $clean = $this->clean($data);

        return $this->guardRace(fn () => DB::transaction(function () use ($id, $clean, $actor, $confirmHomonym): array {
            $holder = DB::table('permanent_holders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['status' => 'Ce titulaire est clos : il ne se modifie plus.']);
            }
            $changed = array_values(array_filter(self::FIELDS, fn (string $field) => (string) ($holder->{$field} ?? '') !== (string) ($clean[$field] ?? '')));
            if ($changed === []) {
                return [];
            }
            if (array_intersect($changed, ['full_name', 'organisation']) !== []) {
                $this->preventDuplicates($clean, $id, $confirmHomonym);
            }
            DB::table('permanent_holders')->where('id', $id)->update([...$clean, 'updated_by' => $actor->id, 'updated_at' => now()]);
            // La catégorie institutionnelle est une étiquette : la changer ne modifie aucune autorisation d'accès.
            app(Audit::class)->append('titulaire.modifie', $actor->id, ['holder_id' => $id, 'identifiant' => $holder->identifier, 'champs' => $changed]);

            return $changed;
        }));
    }

    /** Deux créations simultanées du même titulaire : l'index d'unicité tranche, l'utilisateur reçoit le même message que pour un doublon détecté. */
    private function guardRace(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['full_name' => 'Un titulaire actif porte déjà ce nom dans cette organisation : aucun doublon n’est créé.']);
            }
            throw $e;
        }
    }

    /** Clôture irréversible : l'identifiant reste réservé à ce titulaire pour toujours. */
    public function close(string $id, string $reason, User $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Indiquez un motif de clôture (200 caractères au plus).']);
        }
        DB::transaction(function () use ($id, $reason, $actor): void {
            $holder = DB::table('permanent_holders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['status' => 'Ce titulaire est déjà clos.']);
            }
            $today = now()->toDateString();
            DB::table('permanent_holders')->where('id', $id)->update([
                'status' => 'clos', 'closed_at' => now(), 'closed_reason' => $reason,
                'engagement_ends_on' => $holder->engagement_ends_on ?? ($holder->engagement_starts_on !== null && $holder->engagement_starts_on > $today ? $holder->engagement_starts_on : $today),
                'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            // Le motif peut contenir des informations personnelles : il reste au registre et n'entre pas dans le journal.
            app(Audit::class)->append('titulaire.clos', $actor->id, ['holder_id' => $id, 'identifiant' => $holder->identifier]);
        });
    }

    public function permanentContext(): ?object
    {
        return DB::table('events')->where('is_permanent', true)->first(['id', 'name', 'status']);
    }

    /** Crée le contexte permanent protégé (un seul). Il héberge plus tard les cartes permanentes ; aucun comportement de carte ici. */
    public function createPermanentContext(User $actor): string
    {
        return DB::transaction(function () use ($actor): string {
            DB::statement('SELECT pg_advisory_xact_lock(731903)');
            if ($existing = $this->permanentContext()) {
                return $existing->id;
            }
            $id = (string) Str::uuid();
            $institution = app(Institution::class)->profile();
            DB::table('events')->insert([
                'id' => $id, 'name' => 'Contexte permanent — '.$institution['name'], 'site' => null,
                'starts_at' => now(), 'ends_at' => '2099-12-31 23:59:59+00', 'is_permanent' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(Audit::class)->append('contexte_permanent.cree', $actor->id, ['event_id' => $id]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $text = function (string $key, int $max, bool $required = false) use ($data): ?string {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                if ($required) {
                    throw ValidationException::withMessages([$key => 'Ce champ est obligatoire.']);
                }

                return null;
            }
            if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
                throw ValidationException::withMessages([$key => 'Valeur invalide ou trop longue.']);
            }

            return $value;
        };
        $date = function (string $key) use ($data): ?string {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                return null;
            }
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw ValidationException::withMessages([$key => 'Date au format AAAA-MM-JJ.']);
            }

            return $value;
        };
        $category = trim((string) ($data['institutional_category'] ?? ''));
        if ($category !== '' && ! DB::table('institutional_categories')->where('code', $category)->exists()) {
            throw ValidationException::withMessages(['institutional_category' => 'Catégorie institutionnelle inconnue.']);
        }
        $from = $date('engagement_starts_on');
        $until = $date('engagement_ends_on');
        if ($from !== null && $until !== null && $until < $from) {
            throw ValidationException::withMessages(['engagement_ends_on' => 'La fin de l’engagement précède son début.']);
        }

        return [
            'full_name' => $text('full_name', 160, true), 'organisation' => $text('organisation', 160), 'position' => $text('position', 160),
            'institutional_category' => $category === '' ? null : $category, 'engagement_starts_on' => $from, 'engagement_ends_on' => $until,
        ];
    }

    /** @param array<string, mixed> $clean */
    private function preventDuplicates(array $clean, ?string $ignoreId, bool $confirmHomonym): void
    {
        $same = DB::table('permanent_holders')->whereRaw('public.ikoma_normalize(full_name) = public.ikoma_normalize(?)', [$clean['full_name']])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))->lockForUpdate()->get(['id', 'status', 'organisation']);
        $org = (string) ($clean['organisation'] ?? '');
        $normalize = fn (string $v): string => (string) DB::selectOne('SELECT public.ikoma_normalize(?) AS n', [$v])->n;
        foreach ($same as $row) {
            if ($row->status === 'actif' && $normalize((string) $row->organisation) === $normalize($org)) {
                throw ValidationException::withMessages(['full_name' => 'Un titulaire actif porte déjà ce nom dans cette organisation : aucun doublon n’est créé.']);
            }
        }
        if ($same->isNotEmpty() && ! $confirmHomonym) {
            throw ValidationException::withMessages(['confirm_homonym' => 'Un titulaire de même nom existe déjà (autre organisation ou titulaire clos). Confirmez qu’il s’agit d’une autre personne pour continuer.']);
        }
    }
}
