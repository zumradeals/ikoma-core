<?php

namespace App\Services;

use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;

final class PrivatePhoto
{
    /** Reconstruction depuis les pixels : ni EXIF, ni IPTC, ni commentaire, ni contenu ajouté après le JPEG. */
    public function clean(string $jpeg): string
    {
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            throw new DeskRefused('photo_invalide', 'La photo n’est pas acceptée : reprenez-la.');
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
