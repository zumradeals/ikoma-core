<?php

namespace App\Services;

use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;

final class PrivatePhoto
{
    /** Validation native ; réencodage des photos porteuses de métadonnées ou de contenu ajouté. */
    public function clean(string $jpeg): string
    {
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            throw new DeskRefused('photo_invalide', 'La photo n’est pas acceptée : reprenez-la.');
        }
        // Garder les octets d'une photo déjà propre : contrat d'intégrité L5. Les seuls commentaires admis sont la signature technique GD, sans donnée libre.
        $dirty = preg_match('/\xff[\xe1-\xef]/', $jpeg) === 1 || strpos($jpeg, "\xff\xd9") !== strlen($jpeg) - 2;
        preg_match_all('/\xff\xfe(..)/s', $jpeg, $comments, PREG_OFFSET_CAPTURE);
        foreach ($comments[0] as $match) {
            $length = unpack('nlength', substr($jpeg, $match[1] + 2, 2))['length'];
            $comment = substr($jpeg, $match[1] + 4, max(0, $length - 2));
            if (preg_match('/\ACREATOR: gd-jpeg v1\.0 \(using IJG JPEG v[0-9]+\), quality = [0-9]+\n?\z/', $comment) !== 1) {
                $dirty = true;
            }
        }
        preg_match_all('/\xff\xe0(..)/s', $jpeg, $app0, PREG_OFFSET_CAPTURE);
        foreach ($app0[0] as $match) {
            if (substr($jpeg, $match[1] + 2, 7) !== "\x00\x10JFIF\x00" || substr($jpeg, $match[1] + 16, 2) !== "\x00\x00") {
                $dirty = true;
            }
        }
        if (! $dirty) {
            return $jpeg;
        }
        ob_start();
        try {
            if (! imagejpeg($image, null, 85)) {
                throw new \RuntimeException('Encodage impossible.');
            }
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (! is_string($bytes) || strlen($bytes) > Desk::PHOTO_MAX_BYTES) {
            throw new DeskRefused('photo_invalide', 'La photo n’est pas acceptée : reprenez-la.');
        }

        return $bytes;
    }
}
