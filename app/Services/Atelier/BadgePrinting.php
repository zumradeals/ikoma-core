<?php

namespace App\Services\Atelier;

use App\Models\User;
use App\Services\Audit;
use App\Services\BadgeArtwork;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Préparation (planification) et rendu des documents d'impression de l'Atelier, parcours visiteurs.
 *
 * - Un tirage officiel porte sur de vrais badges d'un lot (PVC, planche A4 ou étiquettes) : le lot est marqué « exposé » AVANT le rendu des jetons.
 * - Un essai (exemple) ne lit aucun lot : badge fictif, QR d'exemple, aucune exposition.
 * - Chaque tirage est tracé (PrintTrail) ; l'Atelier n'écrit jamais dans les badges, associations ou réservations.
 * Les moteurs de rendu restent distincts (badges : `BadgeArtwork` + `badges.card` ; étiquettes : `LabelSheets` + `badges.labels`).
 */
final class BadgePrinting
{
    public function __construct(
        private readonly BadgeArtwork $artwork,
        private readonly LabelSheets $labels,
        private readonly PrintTrail $trail,
    ) {}

    /**
     * Tirage officiel de badges (format pvc ou a4) : valide, sélectionne (marquage d'exposition), trace « document généré ».
     *
     * @param  array<string, mixed>  $input
     */
    public function planBadges(User $actor, array $input, ?string $reason): string
    {
        if (($input['mode'] ?? '') === 'nominatif') {
            Gate::authorize('listes.importer');
        }
        $options = $this->artwork->validateInput($input);
        $badges = $this->artwork->select($options);
        $min = $badges->min('number');
        $max = $badges->max('number');
        $run = $this->trail->generate($actor, [
            'nature' => 'officiel', 'event_id' => $options['event'], 'batch_id' => $options['batch'], 'format' => $options['format'], 'side' => $options['side'],
            'design' => $options['design'], 'mode' => $options['mode'] ?? 'reutilisable', 'planned_count' => $badges->count(),
            'first_number' => $min === null ? null : (int) $min, 'last_number' => $max === null ? null : (int) $max, 'options' => $this->storable($options),
        ], $reason);
        // Action de journal historique conservée (chaîne d'audit) ; son sens précis : « document généré ».
        app(Audit::class)->append('badge.impression_preparee', $actor->id, [
            'event_id' => $options['event'], 'batch_id' => $options['batch'], 'modele' => $options['design'], 'format' => $options['format'], 'face' => $options['side'],
            'nombre' => $badges->count(), 'premier' => $badges->firstOrFail()->code, 'dernier' => $badges->reverse()->firstOrFail()->code, 'tirage' => $run,
        ]);

        return $run;
    }

    /**
     * Tirage officiel d'étiquettes A4.
     *
     * @param  array<string, mixed>  $input
     */
    public function planLabels(User $actor, array $input, ?string $reason): string
    {
        $q = $this->labels->validate($input);
        $data = $this->labels->build($q);
        if ($data['tooMany']) {
            throw ValidationException::withMessages(['to' => 'Choisissez une plage de '.LabelSheets::MAX_LABELS.' étiquettes au plus.']);
        }
        if ($data['count'] === 0) {
            throw ValidationException::withMessages(['from' => 'Aucun badge dans cette plage. Vérifiez les numéros du lot.']);
        }
        $event = (string) DB::table('badge_batches')->where('id', $q['batch'])->value('event_id');

        return $this->trail->generate($actor, [
            'nature' => 'officiel', 'event_id' => $event, 'batch_id' => $q['batch'], 'format' => 'etiquettes', 'side' => 'recto', 'design' => null, 'mode' => null,
            'planned_count' => $data['count'], 'first_number' => $data['numbers'] === [] ? null : (int) min($data['numbers']), 'last_number' => $data['numbers'] === [] ? null : (int) max($data['numbers']),
            'options' => ['batch' => $q['batch'], 'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null, 'cols' => $data['cols'], 'rows' => $data['rows']],
        ], $reason);
    }

    /**
     * Essai marqué : badge d'exemple (recto et verso), aucun vrai QR, aucun lot lu, aucune exposition.
     *
     * @param  array<string, mixed>  $input
     */
    public function planSpecimen(User $actor, array $input): string
    {
        $options = $this->specimenOptions($input);

        return $this->trail->generate($actor, [
            'nature' => 'essai', 'event_id' => $options['event'], 'batch_id' => null, 'format' => $options['format'], 'side' => 'recto', 'design' => $options['design'], 'mode' => $options['mode'],
            'planned_count' => 2, 'first_number' => null, 'last_number' => null, 'options' => $options,
        ], null);
    }

    /**
     * Options d'un exemple : mêmes choix visuels qu'un tirage, sans lot ni plage ni donnée personnelle.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function specimenOptions(array $input): array
    {
        $data = Validator::make($input, [
            'event' => 'required|uuid|exists:events,id',
            'design' => ['required', Rule::in(['visiteur', 'vip', 'prestataire'])],
            'title' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'orientation' => ['required', Rule::in(['portrait', 'paysage'])],
            'mode' => ['sometimes', Rule::in(['reutilisable', 'nominatif'])],
            'show_photo' => 'sometimes|boolean', 'show_org' => 'sometimes|boolean', 'show_period' => 'sometimes|boolean',
            'format' => ['sometimes', Rule::in(['pvc', 'a4'])],
            'cols' => 'sometimes|integer|between:1,3', 'rows' => 'sometimes|integer|between:1,5',
        ], ['primary.regex' => 'Choisissez une couleur valide.', 'accent.regex' => 'Choisissez une couleur valide.'])->validate();
        $data['mode'] ??= 'reutilisable';
        $data['format'] ??= 'pvc';
        $data['cols'] ??= 2;
        $data['rows'] ??= 5;
        if ($data['format'] === 'a4') {
            [$width, $height] = BadgeArtwork::dimensions($data['orientation']);
            if ($data['cols'] * $width > 190 || $data['rows'] * $height > 277) {
                throw ValidationException::withMessages(['cols' => 'Les badges ne tiennent pas sur une feuille A4 à taille réelle. Réduisez les lignes ou les colonnes.']);
            }
        }

        return $data;
    }

    /**
     * Aperçu d'un modèle : recto et verso d'exemple. Aucun jeton lu, aucune exposition.
     *
     * @param  array<string, mixed>  $options
     * @return array{recto: string, verso: string, orientation: string}
     */
    public function specimenPreview(array $options): array
    {
        $options = $this->specimenOptions($options);

        return ['recto' => $this->artwork->specimenCard($options, 'recto'), 'verso' => $this->artwork->specimenCard($options, 'verso'), 'orientation' => $options['orientation']];
    }

    /** Page d'impression d'un tirage déjà tracé : relit ses paramètres mémorisés, revalide, rend. */
    public function document(Request $request, string $runId): Response
    {
        $run = $this->trail->find($runId);
        /** @var array<string, mixed> $options */
        $options = json_decode((string) $run->options, true, 512, JSON_THROW_ON_ERROR);
        if ($run->nature === 'essai') {
            return $this->specimenDocument($request, $run, $this->specimenOptions($options));
        }
        if ($run->format === 'etiquettes') {
            return $this->labelsDocument($request, $run, $options);
        }

        return $this->badgesDocument($request, $run, $this->artwork->validateInput($options));
    }

    /** @param array<string, mixed> $options */
    private function badgesDocument(Request $request, object $run, array $options): Response
    {
        $badges = $this->artwork->select($options);
        $perSheet = $options['format'] === 'pvc' ? 1 : (int) $options['cols'] * (int) $options['rows'];
        $cards = $badges->map(fn (stdClass $badge) => $this->artwork->card($badge, $options))->all();

        return $this->respond($request, 'badges.artwork-print', [
            'options' => $options, 'sheets' => array_chunk($cards, $perSheet), 'count' => count($cards), 'dimensions' => BadgeArtwork::dimensions($options['orientation']),
            'runId' => $run->id, 'nature' => $run->nature, 'title' => 'Impression des badges', 'heading' => count($cards).' badge(s) · '.strtoupper($options['format']).' · '.$options['side'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private function specimenDocument(Request $request, object $run, array $options): Response
    {
        $perSheet = $options['format'] === 'pvc' ? 1 : (int) $options['cols'] * (int) $options['rows'];
        $cards = [$this->artwork->specimenCard($options, 'recto'), $this->artwork->specimenCard($options, 'verso')];

        return $this->respond($request, 'badges.artwork-print', [
            'options' => [...$options, 'side' => 'recto'], 'sheets' => array_chunk($cards, $perSheet), 'count' => 2, 'dimensions' => BadgeArtwork::dimensions($options['orientation']),
            'runId' => $run->id, 'nature' => 'essai', 'title' => 'Essai de modèle', 'heading' => 'ESSAI · exemple recto et verso · '.strtoupper($options['format']).' · sans valeur',
        ]);
    }

    /** @param array<string, mixed> $options */
    private function labelsDocument(Request $request, object $run, array $options): Response
    {
        $data = $this->labels->build($this->labels->validate($options));
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);
        $html = view('badges.labels', [...$data, 'nonce' => $nonce, 'runId' => $run->id])->render();

        return response($html)->header('Cache-Control', 'no-store, private')->header('Content-Type', 'text/html; charset=UTF-8')->header('Referrer-Policy', 'no-referrer');
    }

    /** Page d'essai d'alignement commune : aucun QR, aucune donnée personnelle, aucune trace. */
    public function alignment(Request $request): Response
    {
        $data = Validator::make($request->query(), [
            'format' => ['required', Rule::in(['pvc', 'a4'])], 'orientation' => ['required', Rule::in(['portrait', 'paysage'])],
            'cols' => 'nullable|integer|between:1,3', 'rows' => 'nullable|integer|between:1,5',
        ])->validate();
        $cols = (int) ($data['cols'] ?? 2);
        $rows = (int) ($data['rows'] ?? 5);
        [$width, $height] = BadgeArtwork::dimensions($data['orientation']);
        if ($data['format'] === 'a4' && ($cols * $width > 190 || $rows * $height > 277)) {
            throw ValidationException::withMessages(['cols' => 'Les badges ne tiennent pas sur une feuille A4 à taille réelle. Réduisez les lignes ou les colonnes.']);
        }

        return $this->respond($request, 'badges.atelier-alignment', ['format' => $data['format'], 'orientation' => $data['orientation'], 'cols' => $cols, 'rows' => $rows, 'dimensions' => [$width, $height]]);
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, string $view, array $data): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);

        return response()->view($view, [...$data, 'nonce' => $nonce])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Options mémorisées avec le tirage : uniquement des choix de présentation et la sélection (jamais de donnée personnelle).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function storable(array $options): array
    {
        return array_intersect_key($options, array_flip(['event', 'batch', 'design', 'title', 'primary', 'accent', 'orientation', 'mode', 'personal_allowed', 'show_photo', 'show_org', 'show_period', 'format', 'cols', 'rows', 'side', 'from', 'to']));
    }
}
