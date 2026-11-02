<?php

namespace App\Services\Atelier;

use App\Services\CategorySymbol;
use App\Services\QrSvg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Étiquettes A4 d'un lot (format « étiquettes » de l'Atelier) : sélection, validation et données de la page d'impression.
 * Même rendu qu'avant (vue `badges.labels`) ; le marquage d'exposition passe par le point unique de l'Atelier.
 */
final class LabelSheets
{
    public const MAX_LABELS = 1000;

    public const MARGIN_MM = 10;

    /**
     * @param  array<string, mixed>  $input
     * @return array{batch: string, from?: int|null, to?: int|null, cols?: int|null, rows?: int|null}
     */
    public function validate(array $input): array
    {
        /** @var array{batch: string, from?: int|null, to?: int|null, cols?: int|null, rows?: int|null} $data */
        $data = Validator::make($input, [
            'batch' => ['required', 'uuid', Rule::exists('badge_batches', 'id')],
            'from' => 'nullable|integer|min:1', 'to' => 'nullable|integer|min:1|gte:from',
            'cols' => 'nullable|integer|between:1,4', 'rows' => 'nullable|integer|between:1,10',
        ])->validate();

        return $data;
    }

    /**
     * Marque le lot comme exposé (les jetons vont être rendus) puis prépare les étiquettes de la plage.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public function build(array $q): array
    {
        app(ExposureMarker::class)->markBatch($q['batch']);
        $cols = (int) ($q['cols'] ?? 3);
        $rows = (int) ($q['rows'] ?? 5);
        $number = "CASE WHEN b.code ~ '^B-[0-9]+\$' THEN substring(b.code from 3)::integer END";
        $query = DB::table('badges as b')->leftJoin('categories as c', 'c.id', '=', 'b.category_id')->where('b.badge_batch_id', $q['batch'])
            ->selectRaw("b.code, b.qr_token, ($number) as number, c.id as category_id, c.name as category_name, c.color as category_color, c.event_id")
            ->orderByRaw("($number), b.code");
        if (isset($q['from'])) {
            $query->whereRaw("($number) >= ?", [(int) $q['from']]);
        }
        if (isset($q['to'])) {
            $query->whereRaw("($number) <= ?", [(int) $q['to']]);
        }
        $badges = $query->limit(self::MAX_LABELS + 1)->get();
        $tooMany = $badges->count() > self::MAX_LABELS;
        $categoryIds = DB::table('categories')->where('event_id', DB::table('badge_batches')->where('id', $q['batch'])->value('event_id'))->orderBy('id')->pluck('id')->all();
        $labels = [];
        foreach ($tooMany ? [] : $badges as $b) {
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $b->category_color) === 1 ? $b->category_color : '#334155';
            $luminance = (0.299 * hexdec(substr($color, 1, 2)) + 0.587 * hexdec(substr($color, 3, 2)) + 0.114 * hexdec(substr($color, 5, 2))) / 255;
            $labels[] = [
                'code' => $b->code, 'number' => $b->number, 'color' => $color, 'fg' => $luminance > 0.6 ? '#000' : '#fff',
                'category' => $b->category_name ?? 'Sans catégorie', 'symbol' => $b->category_id ? CategorySymbol::for($b->category_name, $b->category_id, $categoryIds) : '?',
                'svg' => app(QrSvg::class)->render(QrSvg::payload($b->qr_token), 'QR du badge '.$b->code),
            ];
        }
        $cellW = (210 - 2 * self::MARGIN_MM) / $cols;
        $cellH = (297 - 2 * self::MARGIN_MM) / $rows;
        // Étiquette en colonne : bandeau de catégorie, code lisible aussi grand que la largeur le permet, puis QR et numéro.
        $bandH = min(9.0, $cellH * 0.2);
        $codeSize = min($cellW / 3.9, $cellH * 0.28);
        $qrSize = max(10.0, min($cellH - $bandH - $codeSize * 1.15 - 2.5, $cellW * 0.62));

        return [
            'sheets' => array_chunk($labels, $cols * $rows), 'cols' => $cols, 'rows' => $rows, 'query' => $q, 'margin' => self::MARGIN_MM,
            'tooMany' => $tooMany, 'max' => self::MAX_LABELS, 'count' => count($labels),
            'numbers' => array_values(array_filter(array_column($labels, 'number'), fn ($n) => $n !== null)),
            'size' => ['band' => round($bandH, 1), 'qr' => round($qrSize, 1), 'code' => round($codeSize, 1), 'bandFont' => round($bandH * 0.55, 1), 'numFont' => round(min($qrSize * 0.22, 6.0), 1)],
        ];
    }
}
