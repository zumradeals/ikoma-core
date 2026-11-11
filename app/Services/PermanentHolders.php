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
    public const FIELDS = ['full_name', 'first_name', 'last_name', 'organisation', 'position', 'quality', 'institutional_category', 'engagement_starts_on', 'engagement_ends_on'];

    /** Plafond d'une opération groupée (sélection multiple) : évite une opération irréfléchie sur tout le registre. */
    public const BULK_MAX = 100;

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
    public function paginate(?string $query, string $status, ?string $category, int $perPage = 25, ?string $photo = null): LengthAwarePaginator
    {
        // Colonnes explicites : le chemin du fichier de portrait ne quitte jamais le serveur, seule sa présence est exposée.
        $q = DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')
            ->select('h.id', 'h.identifier', 'h.full_name', 'h.first_name', 'h.last_name', 'h.organisation', 'h.position', 'h.quality', 'h.institutional_category', 'h.engagement_starts_on', 'h.engagement_ends_on', 'h.status', 'h.closed_at', 'h.closed_reason', 'h.photo_print_allowed',
                'c.label as category_label', 'c.color as category_color', 'c.symbol as category_symbol')
            ->selectRaw('(h.photo_file IS NOT NULL) AS has_photo');
        if (in_array($status, ['actif', 'clos'], true)) {
            $q->where('h.status', $status);
        }
        if ($category === 'aucune') {
            $q->whereNull('h.institutional_category');
        } elseif ($category !== null && $category !== '') {
            $q->where('h.institutional_category', $category);
        }
        if ($photo === 'avec') {
            $q->whereNotNull('h.photo_file');
        } elseif ($photo === 'sans') {
            $q->whereNull('h.photo_file');
        }
        $term = trim((string) $query);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->whereRaw('public.ikoma_normalize(h.full_name) LIKE public.ikoma_normalize(?)', [$like])
                ->orWhereRaw("public.ikoma_normalize(coalesce(h.organisation, '')) LIKE public.ikoma_normalize(?)", [$like])
                ->orWhereRaw("public.ikoma_normalize(coalesce(h.position, '')) LIKE public.ikoma_normalize(?)", [$like])
                ->orWhereRaw("public.ikoma_normalize(coalesce(h.quality, '')) LIKE public.ikoma_normalize(?)", [$like])
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
    /**
     * Crée un profil permanent.
     *  - Cas A (`$personId` fourni) : le profil se RATTACHE à une personne existante, explicitement choisie ; aucune ligne n'est créée dans `persons`.
     *    Le nom et l'organisation viennent de la personne (référence canonique) ; seuls les champs du profil (fonction, qualité, catégorie, engagement) sont saisis.
     *  - Cas B (sans `$personId`) : nouvelle personne + profil dans la même transaction. Si une personne de même nom existe déjà, la création n'a lieu
     *    que sur confirmation explicite (`$confirmHomonym`) : aucun rapprochement ni fusion automatique sur le nom.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, bool $confirmHomonym = false, ?string $personId = null): string
    {
        if ($personId !== null) {
            return $this->guardRace(fn () => DB::transaction(fn (): string => $this->attachToPerson($personId, $data, $actor)));
        }
        $clean = $this->clean($data);

        return $this->guardRace(fn () => DB::transaction(function () use ($clean, $actor, $confirmHomonym): string {
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 7))', ['personnes']);
            $this->preventDuplicates($clean, null, $confirmHomonym);

            return $this->insertHolder($clean, $actor);
        }));
    }

    /**
     * Rattache un profil permanent à une personne EXISTANTE (cas A). Verrouille la personne : deux demandes simultanées pour la même personne se
     * suivent, la seconde est refusée (et l'index unique `permanent_holders_person_unique` reste le dernier garde-fou).
     *
     * @param  array<string, mixed>  $data
     */
    private function attachToPerson(string $personId, array $data, User $actor): string
    {
        $person = DB::table('persons')->where('id', $personId)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['person_id' => 'Personne introuvable : sélectionnez une identité existante.']);
        if ($person->status !== 'actif') {
            throw ValidationException::withMessages(['person_id' => 'Cette personne est archivée : aucun profil permanent ne peut lui être ajouté.']);
        }
        if (DB::table('permanent_holders')->where('person_id', $personId)->exists()) {
            throw ValidationException::withMessages(['person_id' => 'Cette personne a déjà un profil permanent : aucun second profil n’est créé.']);
        }
        $clean = $this->clean([...$data, 'full_name' => $person->full_name, 'first_name' => $person->first_name, 'last_name' => $person->last_name, 'organisation' => $person->organisation]);
        // Référence canonique : l'identité est celle de `persons`, telle quelle.
        $clean = [...$clean, 'full_name' => $person->full_name, 'first_name' => $person->first_name, 'last_name' => $person->last_name, 'organisation' => $person->organisation];

        return $this->insertHolder($clean, $actor, ['identite' => 'reutilisee'], $personId);
    }

    /**
     * Enregistre UNE fiche dans la transaction en cours (identifiant émis une seule fois). Elle ne crée jamais de carte, de badge,
     * de fiche de participation ni d'habilitation : enregistrer une personne est une opération d'identité, rien d'autre.
     *
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $audit  éléments non personnels ajoutés au journal (par exemple l'identifiant de l'import)
     */
    public function insertHolder(array $clean, User $actor, array $audit = [], ?string $personId = null): string
    {
        $sequence = (int) DB::selectOne("SELECT nextval('permanent_holder_identifier_seq') AS n")->n;
        $id = (string) Str::uuid();
        $identifier = $this->formatIdentifier($sequence);
        DB::table('permanent_holders')->insert([
            'id' => $id, 'identifier' => $identifier, 'identifier_seq' => $sequence, ...$clean, ...($personId !== null ? ['person_id' => $personId] : []), 'status' => 'actif',
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(Audit::class)->append('titulaire.cree', $actor->id, ['holder_id' => $id, 'identifiant' => $identifier, ...($personId !== null ? ['person_id' => $personId] : []), ...$audit]);

        return $id;
    }

    /**
     * Étiquette de catégorie sur plusieurs fiches actives, en une seule transaction. L'étiquette ne confère aucun accès.
     *
     * @param  list<string>  $ids
     * @return array{modifies: int, inchanges: int, clos: int}
     */
    public function setCategoryMany(array $ids, ?string $category, User $actor): array
    {
        $ids = $this->bulkIds($ids);
        if ($category !== null && ! DB::table('institutional_categories')->where('code', $category)->exists()) {
            throw ValidationException::withMessages(['category' => 'Catégorie institutionnelle inconnue.']);
        }

        return DB::transaction(function () use ($ids, $category, $actor): array {
            $result = ['modifies' => 0, 'inchanges' => 0, 'clos' => 0];
            foreach (DB::table('permanent_holders')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $holder) {
                if ($holder->status === 'clos') {
                    $result['clos']++;
                } elseif ($holder->institutional_category === $category) {
                    $result['inchanges']++;
                } else {
                    DB::table('permanent_holders')->where('id', $holder->id)->update(['institutional_category' => $category, 'updated_by' => $actor->id, 'updated_at' => now()]);
                    app(Audit::class)->append('titulaire.modifie', $actor->id, ['holder_id' => $holder->id, 'identifiant' => $holder->identifier, 'champs' => ['institutional_category'], 'groupe' => true]);
                    $result['modifies']++;
                }
            }

            return $result;
        });
    }

    /**
     * Clôture de plusieurs fiches actives avec un même motif, tout ou rien. Irréversible, comme la clôture individuelle.
     *
     * @param  list<string>  $ids
     * @return array{clos: int, deja_clos: int}
     */
    public function closeMany(array $ids, string $reason, User $actor): array
    {
        $ids = $this->bulkIds($ids);

        return DB::transaction(function () use ($ids, $reason, $actor): array {
            $result = ['clos' => 0, 'deja_clos' => 0];
            foreach (DB::table('permanent_holders')->whereIn('id', $ids)->orderBy('id')->get(['id', 'status']) as $holder) {
                if ($holder->status === 'clos') {
                    $result['deja_clos']++;
                } else {
                    $this->close($holder->id, $reason, $actor);
                    $result['clos']++;
                }
            }

            return $result;
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function bulkIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => Str::isUuid($id))));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Sélectionnez au moins une personne.']);
        }
        if (count($ids) > self::BULK_MAX) {
            throw ValidationException::withMessages(['ids' => 'Sélectionnez au plus '.self::BULK_MAX.' personnes à la fois.']);
        }

        return $ids;
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
            $identityFields = array_values(array_intersect($changed, ['full_name', 'first_name', 'last_name', 'organisation']));
            if ($identityFields !== []) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 7))', ['personnes']);
                $this->preventDuplicates($clean, $id, $confirmHomonym, $holder->person_id);
                // `persons` est la référence canonique : la correction s'y écrit d'abord ; le titulaire actif n'en est que le miroir (garde en base).
                DB::table('persons')->where('id', $holder->person_id)->update(['first_name' => $clean['first_name'], 'last_name' => $clean['last_name'],
                    'full_name' => $clean['full_name'], 'organisation' => $clean['organisation'], 'updated_at' => now()]);
                app(Audit::class)->append('identite.corrigee', $actor->id, ['person_id' => $holder->person_id, 'holder_id' => $id, 'champs' => $identityFields]);
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
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'permanent_holders_person_unique')) {
                throw ValidationException::withMessages(['person_id' => 'Cette personne a déjà un profil permanent : aucun second profil n’est créé.']);
            }
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

    /** Crée (une seule fois, sans effet si elle existe) le registre technique protégé qui héberge les cartes permanentes ; ce n'est pas une activité et aucune opération n'en dépend. */
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
                'id' => $id, 'name' => 'Registre des cartes du site — '.$institution['name'], 'site' => null,
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

        // Prénom et nom vont ensemble ; quand ils sont fournis, le nom complet en est dérivé (« Prénom Nom »). Sinon le nom complet saisi est conservé tel quel, sans découpage.
        $first = $text('first_name', 100);
        $last = $text('last_name', 100);
        if (($first === null) !== ($last === null)) {
            throw ValidationException::withMessages([$first === null ? 'first_name' : 'last_name' => 'Renseignez le prénom ET le nom, ou laissez les deux vides et saisissez le nom complet.']);
        }
        if ($first !== null) {
            $full = $first.' '.$last;
            if (mb_strlen($full) > 160) {
                throw ValidationException::withMessages(['last_name' => 'Prénom et nom trop longs (160 caractères au plus ensemble).']);
            }
        } else {
            $full = $text('full_name', 160, true);
        }

        return [
            'full_name' => $full, 'first_name' => $first, 'last_name' => $last, 'organisation' => $text('organisation', 160), 'position' => $text('position', 160), 'quality' => $text('quality', 160),
            'institutional_category' => $category === '' ? null : $category, 'engagement_starts_on' => $from, 'engagement_ends_on' => $until,
        ];
    }

    /**
     * Données saisies (formulaire, ligne d'import) → valeurs contrôlées et prêtes à enregistrer, ou ValidationException.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        return $this->clean($data);
    }

    /**
     * Situation d'une identité par rapport au registre : « ok », « doublon » (un titulaire actif porte déjà ce nom dans cette organisation)
     * ou « homonyme » (même nom, autre organisation ou titulaire clos : à confirmer explicitement). Verrouille les fiches lues.
     *
     * @param  array<string, mixed>  $clean
     */
    public function classify(array $clean, ?string $ignoreId = null, ?string $ignorePersonId = null): string
    {
        $same = DB::table('permanent_holders')->whereRaw('public.ikoma_normalize(full_name) = public.ikoma_normalize(?)', [$clean['full_name']])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))->lockForUpdate()->get(['id', 'status', 'organisation']);
        $org = (string) ($clean['organisation'] ?? '');
        $normalize = fn (string $v): string => (string) DB::selectOne('SELECT public.ikoma_normalize(?) AS n', [$v])->n;
        foreach ($same as $row) {
            if ($row->status === 'actif' && $normalize((string) $row->organisation) === $normalize($org)) {
                return 'doublon';
            }
        }

        // Une personne de même nom (profil permanent ou non) est signalée : jamais rapprochée ni fusionnée automatiquement, seulement confirmée ou choisie par l'administrateur.
        $samePerson = DB::table('persons')->whereRaw('public.ikoma_normalize(full_name) = public.ikoma_normalize(?)', [$clean['full_name']])
            ->when($ignorePersonId !== null, fn ($q) => $q->where('id', '<>', $ignorePersonId))->exists();

        return $same->isNotEmpty() || $samePerson ? 'homonyme' : 'ok';
    }

    /** @param array<string, mixed> $clean */
    private function preventDuplicates(array $clean, ?string $ignoreId, bool $confirmHomonym, ?string $ignorePersonId = null): void
    {
        $state = $this->classify($clean, $ignoreId, $ignorePersonId);
        if ($state === 'doublon') {
            throw ValidationException::withMessages(['full_name' => 'Un titulaire actif porte déjà ce nom dans cette organisation : aucun doublon n’est créé.']);
        }
        if ($state === 'homonyme' && ! $confirmHomonym) {
            throw ValidationException::withMessages(['confirm_homonym' => 'Une personne de même nom existe déjà (autre organisation, titulaire clos ou personne sans profil permanent). Si c’est la même personne, sélectionnez-la depuis « Personnes et habilitations » pour lui ajouter un profil permanent ; sinon confirmez qu’il s’agit d’une autre personne pour continuer.']);
        }
    }
}
