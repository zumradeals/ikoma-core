<?php

namespace App\Services\Imports;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListAnalysis
{
    /** @return array{rows: array<int,array<string,mixed>>, summary:array<string,int>, anomalies:array<int,array<string,mixed>>, sample:array<int,array<string,mixed>>, groups:array<int,array<string,mixed>>} */
    public function analyse(string $path, string $format, string $eventId, bool $createHosts): array
    {
        $event = DB::table('events')->where('id', $eventId)->first();
        abort_unless($event !== null, 404);
        $raw = [];
        $headers = [];
        foreach (app(TableReader::class)->rows($path, $format) as $number => $cells) {
            if (! $headers) {
                $headers = $this->normalize(array_map(fn ($v) => $this->clean(ltrim($v, "\xEF\xBB\xBF"), false), $cells));
                if (count($headers) !== count(array_unique($headers)) || ! in_array('nom complet', $headers, true) || ! in_array('categorie', $headers, true)) {
                    throw ValidationException::withMessages(['file' => 'Les colonnes « nom complet » et « catégorie » sont nécessaires. Chaque en-tête doit être unique.']);
                }

                continue;
            }
            if (count(array_filter($cells, fn ($v) => trim($v) !== '')) === 0) {
                // Les lignes entièrement vides (fréquentes en fin de tableur) sont ignorées.
                continue;
            }
            $values = [];
            foreach ($headers as $i => $key) {
                $values[$key] = $this->clean($cells[$i] ?? '');
            }
            $raw[] = ['line' => $number, 'full_name' => $values['nom complet'] ?? '', 'organisation' => $values['organisation'] ?? '', 'host' => $values['hote'] ?? '', 'category' => $values['categorie'] ?? '', 'from' => $values['debut de validite'] ?? '', 'until' => $values['fin de validite'] ?? ''];
        }
        if (! $headers || ! $raw) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune ligne de données.']);
        }
        $categories = DB::table('categories')->where('event_id', $eventId)->selectRaw('id, public.ikoma_normalize(name) AS normal')->get()->groupBy('normal');
        $hosts = DB::table('hosts')->where('event_id', $eventId)->selectRaw('id, name, public.ikoma_normalize(name) AS normal')->get()->groupBy('normal');
        $strings = [];
        foreach ($raw as $r) {
            foreach (['full_name', 'organisation', 'host', 'category'] as $k) {
                $strings[] = $r[$k];
            }
        }
        $norm = $this->normalize($strings);
        $offset = 0;
        $existing = DB::table('visitors as v')->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')->where('v.event_id', $eventId)->whereNull('v.withdrawn_at')->selectRaw("v.id, v.organisation, h.name AS host, public.ikoma_normalize(v.full_name) AS name_key, public.ikoma_normalize(coalesce(v.organisation,'')) AS org_key, public.ikoma_normalize(coalesce(h.name,'')) AS host_key")->get();
        $identities = [];
        foreach ($existing as $v) {
            $identities[$v->name_key][] = ['key' => json_encode([$v->org_key, $v->host_key]), 'reference' => ['id' => $v->id, 'organisation' => $v->organisation, 'host' => $v->host, 'source' => 'déjà enregistré']];
        }
        $rows = [];
        $anomalies = [];
        $seen = [];
        foreach ($raw as $r) {
            [$nameKey,$orgKey,$hostKey,$catKey] = array_slice($norm, $offset, 4);
            $offset += 4;
            $errors = [];
            if ($r['full_name'] === '' || mb_strlen($r['full_name']) > 160) {
                $errors[] = 'Le nom complet est obligatoire et doit faire au plus 160 caractères.';
            }
            if (mb_strlen($r['organisation']) > 160) {
                $errors[] = 'L’organisation doit faire au plus 160 caractères.';
            }
            $cat = $categories->get($catKey);
            if (! $cat || $cat->count() !== 1) {
                $errors[] = $cat ? 'Plusieurs catégories portent ce nom : corrigez les catégories.' : 'Catégorie inconnue dans cet événement.';
            }
            $host = $hosts->get($hostKey);
            if ($r['host'] !== '' && (! $host || $host->count() !== 1)) {
                if ($host) {
                    $errors[] = 'Plusieurs hôtes portent ce nom : corrigez les hôtes.';
                } elseif (! $createHosts) {
                    $errors[] = 'Hôte inconnu. Corrigez le fichier ou cochez « Créer les hôtes manquants ».';
                } elseif (mb_strlen($r['host']) > 160) {
                    $errors[] = 'Le nom de l’hôte doit faire au plus 160 caractères.';
                }
            }
            $from = $this->date($r['from'], $event->starts_at);
            $until = $this->date($r['until'], $event->ends_at);
            if (! $from || ! $until) {
                $errors[] = 'Date invalide. Utilisez AAAA-MM-JJ, avec HH:MM si nécessaire.';
            } elseif ($until < $from) {
                $errors[] = 'La fin de validité précède le début.';
            }
            $key = json_encode([$orgKey, $hostKey]);
            $identity = json_encode([$nameKey, $orgKey, $hostKey]);
            $related = [];
            foreach ($identities[$nameKey] ?? [] as $other) {
                if ($other['key'] === $key) {
                    $related[] = $other['reference'];
                }
            }
            if (isset($seen[$identity])) {
                $related[] = ['line' => $seen[$identity], 'source' => 'dans le fichier'];
            }
            if ($nameKey !== '' && $related) {
                $errors[] = 'Doublon : même nom, organisation et hôte. Cette ligne ne sera pas importée.';
            }
            $seen[$identity] ??= $r['line'];
            $row = [...$r, 'category_id' => $cat?->first()->id, 'host_id' => $host?->first()->id, 'valid_from' => $from?->format(DATE_ATOM), 'valid_until' => $until?->format(DATE_ATOM), 'name_key' => $nameKey, 'identity_key' => $key, 'errors' => $errors, 'related' => $related];
            $rows[] = $row;
        }
        // Inclut les homonymes antérieurs et postérieurs dans le fichier, sans confondre les doublons.
        foreach ($rows as $r) {
            if (! $r['errors']) {
                $identities[$r['name_key']][] = ['key' => $r['identity_key'], 'reference' => ['line' => $r['line'], 'organisation' => $r['organisation'], 'host' => $r['host'], 'source' => 'dans le fichier']];
            }
        }
        $valid = [];
        $warnings = 0;
        $groups = [];
        $groupNumbers = [];
        $variants = [];
        foreach ($identities as $name => $items) {
            $variants[$name] = count(array_unique(array_column($items, 'key')));
        }
        foreach ($rows as $r) {
            if ($r['errors']) {
                $anomalies[] = ['line' => $r['line'], 'name' => $r['full_name'], 'blocking' => true, 'messages' => $r['errors'], 'related' => $r['related']];

                continue;
            }
            if (($variants[$r['name_key']] ?? 0) > 1) {
                $warnings++;
                if (! isset($groupNumbers[$r['name_key']])) {
                    $number = count($groups) + 1;
                    $groupNumbers[$r['name_key']] = $number;
                    $groups[] = ['number' => $number, 'name' => $r['full_name'], 'references' => array_column($identities[$r['name_key']], 'reference')];
                }
                $anomalies[] = ['line' => $r['line'], 'name' => $r['full_name'], 'blocking' => false, 'messages' => ['Homonyme : demandez un second critère à l’accueil (organisation ou hôte).'], 'related' => [], 'group' => $groupNumbers[$r['name_key']]];
            }
            unset($r['errors'],$r['related'],$r['name_key'],$r['identity_key']);
            $valid[] = $r;
        }

        return ['rows' => $valid, 'summary' => ['read' => count($rows), 'importable' => count($valid), 'blocked' => count($rows) - count($valid), 'warnings' => $warnings], 'anomalies' => $anomalies, 'sample' => array_slice($valid, 0, 10), 'groups' => $groups];
    }

    public function clean(string $value, bool $protect = true): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $protect && preg_match('/^[=+@-]/u', $value) ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalize(array $values): array
    {
        return array_map(fn ($r) => $r->normal, DB::select('SELECT public.ikoma_normalize(value) AS normal FROM jsonb_array_elements_text(?::jsonb) WITH ORDINALITY AS t(value,n) ORDER BY n', [json_encode($values, JSON_THROW_ON_ERROR)]));
    }

    private function date(string $value, string $fallback): ?DateTimeImmutable
    {
        if ($value === '') {
            return new DateTimeImmutable($fallback);
        }
        foreach (['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && (! $errors || (! $errors['warning_count'] && ! $errors['error_count']))) {
                return $date;
            }
        }

        return null;
    }
}
