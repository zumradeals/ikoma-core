<?php

namespace App\Services\Imports;

use App\Models\User;
use App\Services\Audit;
use App\Services\PermanentHolders;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import de personnes dans le registre permanent (R1) : aperçu, puis confirmation.
 *
 * - L'aperçu est chiffré sur le disque privé (une seule préparation par administrateur, une heure) ; rien n'est écrit dans le registre avant la confirmation.
 * - Chaque ligne est contrôlée côté serveur avec les mêmes règles qu'une saisie individuelle. Les lignes en erreur et les doublons ne sont jamais créés.
 * - Un homonyme (même nom, autre organisation ou fiche close) n'est créé que si la ligne est explicitement confirmée.
 * - La confirmation est atomique (une seule transaction : tout ou rien), verrouillée, et rejouable sans effet : l'aperçu est consommé et, à défaut,
 *   les lignes déjà créées sont reconnues comme doublons. Le journal ne contient ni nom ni organisation.
 * - Importer enregistre des identités : aucune carte, aucun badge, aucun QR, aucune habilitation n'est créé.
 */
final class HolderImport
{
    public const MAX_LINES = 500;

    private const TTL = 3600;

    private const LABELS = ['full_name' => 'Nom complet', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'organisation' => 'Organisation', 'position' => 'Fonction', 'quality' => 'Qualité', 'institutional_category' => 'Catégorie', 'engagement_starts_on' => 'Début', 'engagement_ends_on' => 'Fin'];

    private const ALIASES = [
        'prenom' => 'first', 'prenoms' => 'first', 'first_name' => 'first', 'firstname' => 'first',
        'nom' => 'last', 'nom_de_famille' => 'last', 'last_name' => 'last', 'lastname' => 'last', 'surname' => 'last',
        'nom_complet' => 'full', 'nom_et_prenom' => 'full', 'nom_prenom' => 'full', 'full_name' => 'full', 'name' => 'full',
        'organisation' => 'org', 'organisme' => 'org', 'structure' => 'org', 'entreprise' => 'org', 'societe' => 'org',
        'fonction' => 'position', 'poste' => 'position', 'position' => 'position', 'titre' => 'position',
        'qualite' => 'quality', 'quality' => 'quality',
        'categorie' => 'category', 'categorie_institutionnelle' => 'category', 'category' => 'category',
        'debut' => 'start', 'debut_engagement' => 'start', 'date_debut' => 'start', 'engagement_debut' => 'start',
        'fin' => 'end', 'fin_engagement' => 'end', 'date_fin' => 'end', 'engagement_fin' => 'end',
    ];

    public function __construct(private readonly PermanentHolders $holders, private readonly TableReader $reader) {}

    /** Modèle de fichier : en-têtes et une ligne manifestement fictive. */
    public static function template(): string
    {
        return "prenom;nom;organisation;fonction;qualite;categorie;debut_engagement;fin_engagement\nPersonne;Exemple;Organisation exemple;Fonction exemple;Qualité exemple;;2027-01-01;2027-12-31\n";
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, UploadedFile $file): array
    {
        $format = $this->reader->check($file);

        return DB::transaction(function () use ($actor, $file, $format): array {
            $this->lock();
            $analysis = $this->analyse($file->getPathname(), $format);
            $preview = [
                'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'expires_at' => now()->timestamp + self::TTL,
                'filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                'sha' => substr(hash_file('sha256', $file->getPathname()), 0, 16), ...$analysis,
            ];
            // Un nouvel aperçu remplace l'ancien du même administrateur ; l'ancien reste intact tant que le nouveau fichier est refusé (analyse faite avant).
            $this->write($actor->id, $preview);
            app(Audit::class)->append('titulaire.import_apercu', $actor->id, ['id' => $preview['id'], 'empreinte' => $preview['sha'], ...$preview['summary']]);

            return $this->publicPreview($preview);
        });
    }

    /** @return array<string, mixed>|null */
    public function show(User $actor): ?array
    {
        $p = $this->read($actor->id);
        if ($p === null) {
            return null;
        }
        if ($p['expires_at'] <= now()->timestamp) {
            Storage::disk('local')->delete($this->path($actor->id));

            return null;
        }

        return $this->publicPreview($p);
    }

    /**
     * @param  list<int>  $confirmLines  numéros des lignes « homonyme » que l'administrateur confirme comme étant d'autres personnes
     * @return array{created: int, ignored: int, first: string|null, last: string|null}
     */
    public function confirm(User $actor, string $token, array $confirmLines): array
    {
        $confirm = array_flip(array_map('intval', $confirmLines));
        try {
            return DB::transaction(function () use ($actor, $token, $confirm): array {
                $this->lock();
                $p = $this->read($actor->id);
                if ($p === null || ! hash_equals($p['id'], $token) || $p['expires_at'] <= now()->timestamp) {
                    $this->fail('Cet aperçu n’est plus disponible (déjà traité ou expiré). Rechargez le fichier.');
                }
                // Lecture de l'état du registre AVANT toute création : l'import ne se juge pas lui-même.
                $toCreate = [];
                $ignored = 0;
                foreach ($p['rows'] as $row) {
                    $wanted = $row['status'] === 'ok' || ($row['status'] === 'homonyme' && isset($confirm[$row['line']]));
                    if (! $wanted || $row['data'] === null) {
                        $ignored++;

                        continue;
                    }
                    $state = $this->holders->classify($row['data']);
                    // Un doublon apparu depuis l'aperçu, ou un nouvel homonyme non confirmé : la ligne est ignorée, jamais créée.
                    if ($state === 'doublon' || ($state === 'homonyme' && ! isset($confirm[$row['line']]))) {
                        $ignored++;

                        continue;
                    }
                    $toCreate[] = $row['data'];
                }
                $first = $last = null;
                foreach ($toCreate as $clean) {
                    $id = $this->holders->insertHolder($clean, $actor, ['import' => $token]);
                    $identifier = (string) DB::table('permanent_holders')->where('id', $id)->value('identifier');
                    $first ??= $identifier;
                    $last = $identifier;
                }
                app(Audit::class)->append('titulaire.import_confirme', $actor->id, ['id' => $token, 'empreinte' => $p['sha'], 'crees' => count($toCreate), 'ignorees' => $ignored, 'premier' => $first, 'dernier' => $last]);
                // L'aperçu est consommé dans la même transaction : un second envoi ne retrouve rien à créer.
                Storage::disk('local')->delete($this->path($actor->id));

                return ['created' => count($toCreate), 'ignored' => $ignored, 'first' => $first, 'last' => $last];
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                // Deux enregistrements simultanés : l'index d'unicité tranche et la transaction entière est annulée (aucune création partielle).
                $this->fail('Un doublon est apparu pendant l’import : rien n’a été créé. Rechargez le fichier.');
            }
            throw $e;
        }
    }

    public function abandon(User $actor, string $token): void
    {
        DB::transaction(function () use ($actor, $token): void {
            $this->lock();
            $p = $this->read($actor->id);
            if ($p !== null && hash_equals($p['id'], $token)) {
                Storage::disk('local')->delete($this->path($actor->id));
                app(Audit::class)->append('titulaire.import_abandonne', $actor->id, ['id' => $token, 'empreinte' => $p['sha']]);
            }
        });
    }

    /** Supprime les aperçus expirés (tâche planifiée existante). */
    public function purge(): int
    {
        $count = 0;
        foreach (Storage::disk('local')->files('holder-imports') as $path) {
            $p = $this->decode($path);
            if ($p === null || $p['expires_at'] <= now()->timestamp) {
                Storage::disk('local')->delete($path);
                $count++;
            }
        }

        return $count;
    }

    // --- Analyse ----------------------------------------------------------------------------------------------------------------

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, int>, columns: array{recognized: list<string>, ignored: list<string>}} */
    private function analyse(string $path, string $format): array
    {
        $header = null;
        $map = [];
        $ignoredColumns = [];
        $rows = [];
        $categories = $this->categoryIndex();
        $seen = [];
        $count = 0;
        foreach ($this->reader->rows($path, $format) as $number => $cells) {
            if ($header === null) {
                if (count(array_filter($cells, fn ($c) => trim($c) !== '')) === 0) {
                    continue;
                }
                $header = true;
                foreach ($cells as $i => $cell) {
                    $key = self::headerKey($cell, $i === 0);
                    if ($key === '') {
                        continue;
                    }
                    if (isset(self::ALIASES[$key]) && ! in_array(self::ALIASES[$key], array_column($map, 'role'), true)) {
                        $map[$i] = ['role' => self::ALIASES[$key], 'title' => trim($cell)];
                    } else {
                        $ignoredColumns[] = trim($cell);
                    }
                }
                $this->checkColumns(array_column($map, 'role'));

                continue;
            }
            if (count(array_filter($cells, fn ($c) => trim($c) !== '')) === 0) {
                continue;
            }
            if (++$count > self::MAX_LINES) {
                $this->fail('Le fichier dépasse '.self::MAX_LINES.' lignes : découpez-le en plusieurs fichiers.');
            }
            $values = [];
            foreach ($map as $i => $col) {
                $values[$col['role']] = trim($cells[$i] ?? '');
            }
            $rows[] = $this->analyseRow($number, $values, array_column($map, 'role'), $categories, $seen);
        }
        if ($header === null) {
            $this->fail('Le fichier est vide.');
        }
        if ($rows === []) {
            $this->fail('Le fichier ne contient aucune ligne de données sous l’en-tête.');
        }
        $summary = ['lignes' => count($rows), 'a_creer' => 0, 'homonymes' => 0, 'doublons_registre' => 0, 'doublons_fichier' => 0, 'erreurs' => 0];
        foreach ($rows as $row) {
            $summary[['ok' => 'a_creer', 'homonyme' => 'homonymes', 'doublon_registre' => 'doublons_registre', 'doublon_fichier' => 'doublons_fichier', 'erreur' => 'erreurs'][$row['status']]]++;
        }

        return ['rows' => $rows, 'summary' => $summary, 'columns' => ['recognized' => array_column($map, 'title'), 'ignored' => array_values(array_unique($ignoredColumns))]];
    }

    /** @param list<string> $roles */
    private function checkColumns(array $roles): void
    {
        $has = fn (string $r) => in_array($r, $roles, true);
        if ($has('first') && ! $has('last')) {
            $this->fail('Colonne « nom » manquante : avec « prénom », le fichier doit aussi contenir « nom ».');
        }
        if (! $has('first') && $has('last') && $has('full')) {
            $this->fail('Colonnes ambiguës : « nom » avec « nom complet » mais sans « prénom ». Gardez « prénom » + « nom », ou seulement « nom complet ».');
        }
        if (! $has('first') && ! $has('last') && ! $has('full')) {
            $this->fail('Aucune colonne de nom reconnue : ajoutez « prénom » et « nom », ou « nom complet ».');
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $roles
     * @param  array<string, string>  $categories
     * @param  array<string, array<string, true>>  $seen
     * @return array<string, mixed>
     */
    private function analyseRow(int $line, array $values, array $roles, array $categories, array &$seen): array
    {
        $label = trim(($values['first'] ?? '').' '.($values['last'] ?? '').' '.($values['full'] ?? ''));
        $error = fn (string $message): array => ['line' => $line, 'status' => 'erreur', 'message' => $message, 'data' => null, 'label' => mb_substr($label, 0, 160)];
        $input = ['organisation' => $values['org'] ?? '', 'position' => $values['position'] ?? '', 'quality' => $values['quality'] ?? ''];
        $splitColumns = in_array('first', $roles, true);
        if ($splitColumns) {
            $input['first_name'] = $values['first'] ?? '';
            $input['last_name'] = $values['last'] ?? '';
            if (($input['first_name'] !== '' || $input['last_name'] !== '') && ($values['full'] ?? '') !== '') {
                return $error('Renseignez soit prénom et nom, soit nom complet, pas les deux.');
            }
            if ($input['first_name'] === '' && $input['last_name'] === '') {
                $input['full_name'] = $values['full'] ?? '';
            }
        } else {
            // Sans colonne « prénom » : « nom » ou « nom complet » est un nom complet, conservé tel quel (jamais découpé).
            $input['full_name'] = ($values['full'] ?? '') !== '' ? $values['full'] : ($values['last'] ?? '');
        }
        $category = $values['category'] ?? '';
        if ($category !== '') {
            $code = $categories[self::headerKey($category, false)] ?? null;
            if ($code === null) {
                return $error('Catégorie inconnue : « '.mb_substr($category, 0, 60).' ». Utilisez le code (AILCT-01…) ou le libellé exact, ou laissez vide.');
            }
            $input['institutional_category'] = $code;
        }
        foreach (['start' => 'engagement_starts_on', 'end' => 'engagement_ends_on'] as $role => $field) {
            $date = $this->date($values[$role] ?? '');
            if ($date === false) {
                return $error(self::LABELS[$field].' : date non reconnue (AAAA-MM-JJ ou JJ/MM/AAAA).');
            }
            $input[$field] = $date;
        }
        try {
            $clean = $this->holders->sanitize($input);
        } catch (ValidationException $e) {
            $field = (string) array_key_first($e->errors());

            return $error((self::LABELS[$field] ?? $field).' : '.($e->errors()[$field][0] ?? 'valeur invalide').'');
        }
        $name = self::norm($clean['full_name']);
        $org = self::norm((string) ($clean['organisation'] ?? ''));
        $row = ['line' => $line, 'data' => $clean, 'label' => $clean['full_name']];
        if (isset($seen[$name][$org])) {
            return [...$row, 'status' => 'doublon_fichier', 'message' => 'Même personne qu’une ligne précédente du fichier : ignorée.'];
        }
        $inFile = isset($seen[$name]);
        $seen[$name][$org] = true;
        $state = $this->holders->classify($clean);
        if ($state === 'doublon') {
            return [...$row, 'status' => 'doublon_registre', 'message' => 'Une personne active porte déjà ce nom dans cette organisation : ignorée.'];
        }
        if ($state === 'homonyme' || $inFile) {
            return [...$row, 'status' => 'homonyme', 'message' => $inFile && $state !== 'homonyme' ? 'Même nom qu’une autre ligne du fichier, autre organisation : à confirmer.' : 'Même nom qu’une fiche existante (autre organisation ou fiche close) : à confirmer si c’est une autre personne.'];
        }

        return [...$row, 'status' => 'ok', 'message' => null];
    }

    /** @return string|false chaîne vide si la date est absente, false si elle est illisible */
    private function date(string $value): string|false
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})(?:[ T].*)?\z/', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('~\A(\d{1,2})/(\d{1,2})/(\d{4})\z~', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return false;
    }

    /** @return array<string, string> libellé ou code normalisé → code */
    private function categoryIndex(): array
    {
        $index = [];
        foreach (DB::table('institutional_categories')->get(['code', 'label']) as $c) {
            $index[self::headerKey($c->code, false)] = $c->code;
            $index[self::headerKey($c->label, false)] = $c->code;
        }

        return $index;
    }

    private static function headerKey(string $text, bool $first): string
    {
        if ($first) {
            $text = preg_replace('/\A\xEF\xBB\xBF/', '', $text) ?? $text;
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($text))), '_');
    }

    private static function norm(string $value): string
    {
        return (string) DB::selectOne('SELECT public.ikoma_normalize(?) AS n', [$value])->n;
    }

    // --- Stockage de l'aperçu ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function publicPreview(array $p): array
    {
        $rows = array_map(fn (array $r) => [
            'line' => $r['line'], 'status' => $r['status'], 'message' => $r['message'], 'name' => $r['label'],
            'organisation' => $r['data']['organisation'] ?? null, 'position' => $r['data']['position'] ?? null, 'quality' => $r['data']['quality'] ?? null, 'category' => $r['data']['institutional_category'] ?? null,
        ], $p['rows']);

        return ['id' => $p['id'], 'filename' => $p['filename'], 'expires_at' => $p['expires_at'], 'summary' => $p['summary'], 'columns' => $p['columns'], 'rows' => $rows];
    }

    private function path(string $actorId): string
    {
        return 'holder-imports/'.$actorId.'.enc';
    }

    /** @return array<string, mixed>|null */
    private function read(string $actorId): ?array
    {
        return $this->decode($this->path($actorId));
    }

    /** @return array<string, mixed>|null */
    private function decode(string $path): ?array
    {
        $raw = Storage::disk('local')->get($path);
        if ($raw === null) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            // Aperçu illisible (clé changée, fichier corrompu) : retiré, il ne bloque pas les imports suivants.
            Storage::disk('local')->delete($path);

            return null;
        }
    }

    /** @param array<string, mixed> $p */
    private function write(string $actorId, array $p): void
    {
        if (! Storage::disk('local')->put($this->path($actorId), Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)))) {
            throw new \RuntimeException('Stockage privé indisponible.');
        }
    }

    private function lock(): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(731904)');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
