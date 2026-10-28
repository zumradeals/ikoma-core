<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/** Dessine un QR code en SVG compact, destiné à être inséré dans une page HTML (une seule forme, aucune ressource externe) à partir de l’encodeur bacon/bacon-qr-code. */
final class QrSvg
{
    public const PREFIX = 'IKOMA1:';

    private const QUIET_ZONE = 4;

    public static function payload(string $token): string
    {
        return self::PREFIX.$token;
    }

    public function render(string $payload, string $label): string
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::Q(), 'ISO-8859-1', null, false)->getMatrix();
        $size = $matrix->getWidth();
        $path = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }
                $start = $x;
                while ($x + 1 < $size && $matrix->get($x + 1, $y) === 1) {
                    $x++;
                }
                $path .= 'M'.($start + self::QUIET_ZONE).' '.($y + self::QUIET_ZONE).'h'.($x - $start + 1).'v1h-'.($x - $start + 1).'z';
            }
        }
        $total = $size + 2 * self::QUIET_ZONE;

        return '<svg viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges" role="img" aria-label="'.e($label).'"><rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path fill="#000" d="'.$path.'"/></svg>';
    }
}
