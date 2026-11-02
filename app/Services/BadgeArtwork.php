<?php

namespace App\Services;

use App\Models\Visitor;
use App\Services\Atelier\ExposureMarker;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Présentation seule : aucune écriture dans les badges, associations ou autorisations. */
final class BadgeArtwork
{
    public const MAX_BADGES = 1000;

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return ['design' => 'visiteur', 'title' => 'IKOMA ACCESS', 'primary' => '#0066B3', 'accent' => '#F75C03', 'orientation' => 'paysage'];
    }

    /** @return array<string, mixed> */
    public function validate(Request $request, bool $selection = true): array
    {
        return $this->validateInput($request->all(), $selection);
    }

    /**
     * Mêmes règles que validate(), à partir d'un tableau : l'Atelier relit les options mémorisées d'un tirage sans requête HTTP.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validateInput(array $input, bool $selection = true): array
    {
        $rules = [
            'event' => 'required|uuid|exists:events,id',
            'design' => ['required', Rule::in(['visiteur', 'vip', 'prestataire'])],
            'title' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'orientation' => ['required', Rule::in(['portrait', 'paysage'])],
            // Le mode nominatif nécessite son propre contrat de confidentialité et ses permissions.
            'mode' => ['sometimes', Rule::in(['reutilisable', 'nominatif'])],
            'personal_allowed' => 'required_if:mode,nominatif|accepted_if:mode,nominatif',
            'show_photo' => 'sometimes|boolean', 'show_org' => 'sometimes|boolean', 'show_period' => 'sometimes|boolean',
        ];
        if ($selection) {
            $rules += [
                'batch' => ['required', 'uuid', Rule::exists('badge_batches', 'id')->where('event_id', $input['event'] ?? null)],
                'from' => 'nullable|integer|min:1|max:2147483647',
                'to' => 'nullable|integer|min:1|max:2147483647|gte:from',
                'format' => ['required', Rule::in(['pvc', 'a4'])],
                'cols' => 'required|integer|between:1,3', 'rows' => 'required|integer|between:1,5',
                'side' => ['required', Rule::in(['recto', 'verso'])],
            ];
        }
        $data = Validator::make($input, $rules, [
            'batch.exists' => 'Ce lot n’appartient pas à l’événement choisi.',
            'to.gte' => 'Le dernier numéro doit être supérieur ou égal au premier.',
            'primary.regex' => 'Choisissez une couleur valide.', 'accent.regex' => 'Choisissez une couleur valide.',
            'mode.in' => 'Choisissez le mode standard ou nominatif.',
        ])->validate();
        if (($data['mode'] ?? '') === 'nominatif') {
            Gate::authorize('listes.importer');
        }
        if ($selection && $data['format'] === 'a4') {
            [$width, $height] = self::dimensions($data['orientation']);
            if ($data['cols'] * $width > 190 || $data['rows'] * $height > 277) {
                throw ValidationException::withMessages(['cols' => 'Les badges ne tiennent pas sur une feuille A4 à taille réelle. Réduisez les lignes ou les colonnes.']);
            }
        }

        return $data;
    }

    /** @return array{float, float} */
    public static function dimensions(string $orientation): array
    {
        return $orientation === 'portrait' ? [53.98, 85.60] : [85.60, 53.98];
    }

    /** @param array<string, mixed> $options
     * @return Collection<int, stdClass>
     */
    public function select(array $options): Collection
    {
        // Un aperçu contient un véritable QR et peut être capturé ou imprimé. Protéger le lot avant de lire ses jetons.
        abort_unless(app(ExposureMarker::class)->markBatch($options['batch'], $options['event']), 404);
        $number = "CASE WHEN b.code ~ '^B-[0-9]+\$' THEN substring(b.code from 3)::integer END";
        $query = DB::table('badges as b')->leftJoin('categories as c', 'c.id', '=', 'b.category_id')
            ->where('b.event_id', $options['event'])->where('b.badge_batch_id', $options['batch'])
            ->selectRaw("b.code, b.qr_token, b.status, b.printed_for_visitor_id, c.name as category, ($number) as number")
            ->orderByRaw("($number), b.code");
        if (isset($options['from'])) {
            $query->whereRaw("($number) >= ?", [(int) $options['from']]);
        }
        if (isset($options['to'])) {
            $query->whereRaw("($number) <= ?", [(int) $options['to']]);
        }
        $badges = $query->limit(self::MAX_BADGES + 1)->get();
        if ($badges->isEmpty()) {
            throw ValidationException::withMessages(['from' => 'Aucun badge dans cette plage. Vérifiez les numéros du lot.']);
        }
        if ($badges->count() > self::MAX_BADGES) {
            throw ValidationException::withMessages(['to' => 'Choisissez une plage de '.self::MAX_BADGES.' badges au maximum.']);
        }

        if (($options['mode'] ?? '') === 'nominatif') {
            $ids = $badges->pluck('printed_for_visitor_id')->filter()->unique();
            if ($badges->contains(fn ($badge) => $badge->printed_for_visitor_id === null) || Visitor::whereIn('id', $ids)->where('event_id', $options['event'])->whereNull('withdrawn_at')->count() !== $ids->count()) {
                throw ValidationException::withMessages(['mode' => 'Chaque badge de la plage doit avoir une destination nominative active.']);
            }
        }

        return $badges;
    }

    /**
     * Badge d'EXEMPLE : données fictives et QR d'exemple (jeton nul) qui ne correspond à aucun badge. Ne lit aucun lot, aucune fiche, aucun portrait,
     * donc n'expose aucun vrai QR et ne marque aucune exposition. Sert à l'aperçu d'un modèle et à l'essai marqué.
     *
     * @param  array<string, mixed>  $options
     */
    public function specimenCard(array $options, string $side): string
    {
        $badge = (object) ['code' => 'B-000', 'qr_token' => str_repeat('0', 64), 'status' => 'Disponible', 'printed_for_visitor_id' => null, 'category' => 'Catégorie exemple', 'number' => 0];
        $person = null;
        if (($options['mode'] ?? '') === 'nominatif' && $side === 'recto') {
            $person = (object) ['full_name' => 'Prénom NOM (exemple)', 'position' => 'Fonction exemple', 'organisation' => 'Organisation exemple', 'valid_from' => now()->startOfDay(), 'valid_until' => now()->addDay()->startOfDay()];
        }
        $options = [...$options, 'side' => $side];

        return view('badges.card', [
            'person' => $person, 'portrait' => null, 'badge' => $badge, 'options' => $options, 'specimen' => true,
            'svg' => $side === 'recto' ? app(QrSvg::class)->render(QrSvg::payload($badge->qr_token), 'QR d’exemple, sans valeur') : '',
        ])->render();
    }

    /** @param array<string, mixed> $options */
    public function card(stdClass $badge, array $options): string
    {
        // Une couleur personnalisée ne touche jamais au QR noir sur blanc ni aux textes noirs.
        $person = null;
        $portrait = null;
        if (($options['mode'] ?? '') === 'nominatif' && ($options['side'] ?? 'recto') === 'recto') {
            Gate::authorize('listes.importer');
            $person = Visitor::where('id', $badge->printed_for_visitor_id)->where('event_id', $options['event'])->whereNull('withdrawn_at')->first();
            if (! $person) {
                throw ValidationException::withMessages(['mode' => 'Cette plage contient un badge sans destination nominative active. Préparez les destinations ou utilisez le mode standard.']);
            }
            if (! empty($options['show_photo'])) {
                $bytes = app(ProfilePortrait::class)->read($person, true);
                $portrait = $bytes ? 'data:image/jpeg;base64,'.base64_encode($bytes) : null;
            }
        }

        return view('badges.card', [
            'person' => $person, 'portrait' => $portrait,
            'badge' => $badge, 'options' => $options,
            'svg' => ($options['side'] ?? 'recto') === 'recto' ? app(QrSvg::class)->render(QrSvg::payload($badge->qr_token), 'QR du badge '.$badge->code) : '',
        ])->render();
    }
}
