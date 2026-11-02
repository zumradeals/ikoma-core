<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modèles de cartes : un modèle par catégorie institutionnelle (couleur, mention, validité affichée, position du QR).
 *
 * Simple par conception : sans ligne en base, un modèle prend les valeurs par défaut de sa catégorie ; l'enregistrer le crée ; le valider fige sa version ;
 * le modifier ensuite le repasse « à valider » (nouvelle version). Les couleurs par défaut sont des familles de couleurs proposées, modifiables, jamais présentées comme officielles.
 */
final class CardModels
{
    /** Mentions par défaut (au singulier), reprises du cahier des charges ; modifiables. */
    public const MENTIONS = [
        'AILCT-01' => 'PERSONNEL AILCT', 'AILCT-02' => 'PARTENAIRE PERMANENT', 'AILCT-03' => 'INTERVENANT', 'AILCT-04' => 'DÉLÉGATION OFFICIELLE',
        'AILCT-05' => 'VISITEUR', 'AILCT-06' => 'ACCOMPAGNATEUR', 'AILCT-07' => 'STAGIAIRE', 'AILCT-08' => 'EXPERT',
    ];

    /** Familles de couleurs du cahier (vert, orange, rouge, or, argent, bronze, bleu, jaune) : teintes provisoires, configurables. */
    public const FAMILY_COLORS = [
        'AILCT-01' => '#2E7D32', 'AILCT-02' => '#EF6C00', 'AILCT-03' => '#C62828', 'AILCT-04' => '#C9A227',
        'AILCT-05' => '#8E9AA6', 'AILCT-06' => '#A9744F', 'AILCT-07' => '#1565C0', 'AILCT-08' => '#F2C230',
    ];

    /**
     * Modèle d'une catégorie, avec les valeurs par défaut appliquées.
     *
     * @return array{category_code: string, label: string, color: string, own_color: ?string, mention: string, show_validity: bool, qr_side: string, version: int, status: string, validated: bool, validated_at: ?string, validated_by: ?string, saved: bool}
     */
    public function forCategory(string $code): array
    {
        $category = DB::table('institutional_categories')->where('code', $code)->first(['code', 'label', 'color']);
        abort_if($category === null, 404);
        $row = DB::table('card_models as m')->leftJoin('users as u', 'u.id', '=', 'm.validated_by')->where('m.category_code', $code)->first(['m.*', 'u.name as validator']);
        $own = $row?->color;
        $fallback = ($category->color === InstitutionalCategories::NEUTRAL_COLOR || ! Institution::validColor((string) $category->color)) ? (self::FAMILY_COLORS[$code] ?? InstitutionalCategories::NEUTRAL_COLOR) : strtoupper((string) $category->color);

        return [
            'category_code' => $code, 'label' => (string) $category->label, 'color' => strtoupper((string) ($own ?? $fallback)), 'own_color' => $own === null ? null : strtoupper($own),
            'mention' => (string) ($row->mention ?? self::MENTIONS[$code] ?? mb_strtoupper((string) $category->label)),
            'show_validity' => (bool) ($row->show_validity ?? true), 'qr_side' => (string) ($row->qr_side ?? 'verso'),
            'version' => (int) ($row->version ?? 1), 'status' => (string) ($row->status ?? 'a_valider'), 'validated' => ($row->status ?? 'a_valider') === 'valide',
            'validated_at' => $row?->validated_at, 'validated_by' => $row?->validator, 'saved' => $row !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(fn (string $code) => $this->forCategory($code), DB::table('institutional_categories')->orderBy('sort_order')->pluck('code')->all());
    }

    /**
     * Valeurs saisies, contrôlées. Une couleur vide revient à la couleur par défaut de la catégorie.
     *
     * @param  array<string, mixed>  $data
     * @return array{color: ?string, mention: string, show_validity: bool, qr_side: string}
     */
    public function clean(string $code, array $data): array
    {
        $color = trim((string) ($data['color'] ?? ''));
        if ($color !== '' && ! Institution::validColor($color)) {
            throw ValidationException::withMessages(['color' => 'Choisissez une couleur valide.']);
        }
        $mention = trim((string) ($data['mention'] ?? ''));
        if ($mention === '') {
            $mention = self::MENTIONS[$code] ?? '';
        }
        if ($mention === '' || mb_strlen($mention) > 60 || preg_match('/[\x00-\x1F\x7F<>]/u', $mention) === 1) {
            throw ValidationException::withMessages(['mention' => 'Mention obligatoire, 60 caractères au plus.']);
        }
        $side = (string) ($data['qr_side'] ?? 'verso');
        if (! in_array($side, ['recto', 'verso'], true)) {
            throw ValidationException::withMessages(['qr_side' => 'Choisissez le recto ou le verso.']);
        }

        return ['color' => $color === '' ? null : strtoupper($color), 'mention' => mb_strtoupper($mention), 'show_validity' => filter_var($data['show_validity'] ?? true, FILTER_VALIDATE_BOOL), 'qr_side' => $side];
    }

    /**
     * Enregistre le modèle. S'il était validé et que quelque chose change, il repasse « à valider » avec une nouvelle version.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(string $code, array $data, User $actor): void
    {
        $clean = $this->clean($code, $data);
        DB::transaction(function () use ($code, $clean, $actor): void {
            DB::table('institutional_categories')->where('code', $code)->lockForUpdate()->firstOrFail();
            $row = DB::table('card_models')->where('category_code', $code)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('card_models')->insert([...$clean, 'category_code' => $code, 'version' => 1, 'status' => 'a_valider', 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit('carte.modele_enregistre', $actor, $code, 1);

                return;
            }
            $changed = $row->color !== $clean['color'] || $row->mention !== $clean['mention'] || (bool) $row->show_validity !== $clean['show_validity'] || $row->qr_side !== $clean['qr_side'];
            if (! $changed) {
                return;
            }
            // Un modèle validé qui change n'est plus validé : nouvelle version à valider avant de servir de modèle officiel.
            $revalidate = $row->status === 'valide';
            DB::table('card_models')->where('category_code', $code)->update([
                ...$clean, 'updated_by' => $actor->id, 'updated_at' => now(),
                'version' => $revalidate ? $row->version + 1 : $row->version, 'status' => 'a_valider', 'validated_at' => null, 'validated_by' => null,
            ]);
            $this->audit('carte.modele_enregistre', $actor, $code, $revalidate ? $row->version + 1 : $row->version);
        });
    }

    /** Valide le modèle tel qu'il est enregistré : date et valideur conservés. */
    public function validate(string $code, User $actor): void
    {
        DB::transaction(function () use ($code, $actor): void {
            DB::table('institutional_categories')->where('code', $code)->lockForUpdate()->firstOrFail();
            if (DB::table('card_models')->where('category_code', $code)->doesntExist()) {
                DB::table('card_models')->insert([...$this->clean($code, []), 'category_code' => $code, 'version' => 1, 'status' => 'a_valider', 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $row = DB::table('card_models')->where('category_code', $code)->lockForUpdate()->first();
            if ($row->status === 'valide') {
                return;
            }
            DB::table('card_models')->where('category_code', $code)->update(['status' => 'valide', 'validated_at' => now(), 'validated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit('carte.modele_valide', $actor, $code, (int) $row->version);
        });
    }

    private function audit(string $action, User $actor, string $code, int $version): void
    {
        app(Audit::class)->append($action, $actor->id, ['categorie' => $code, 'version' => $version]);
    }
}
