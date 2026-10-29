<?php

namespace App\Services;

use App\Models\Visitor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Portrait volontaire, distinct des photos et pièces du contrôle d'identité. */
final class ProfilePortrait
{
    public function clean(string $bytes): string
    {
        $size = @getimagesizefromstring($bytes);
        if (strlen($bytes) > 5 * 1024 * 1024 || ! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $size[0] * $size[1] > 12000000) {
            throw ValidationException::withMessages(['photo' => 'Choisissez un portrait JPEG ou PNG de 5 Mo et 12 millions de pixels au maximum.']);
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'La photo ne peut pas être lue. Choisissez un autre fichier.']);
        }
        $target = imagecreatetruecolor(360, 480);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        $width = min($size[0], $size[1] * .75);
        $height = $width / .75;
        imagecopyresampled($target, $source, 0, 0, (int) (($size[0] - $width) / 2), (int) (($size[1] - $height) / 2), 360, 480, (int) $width, (int) $height);
        ob_start();
        try {
            imagejpeg($target, null, 85);
            $jpeg = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($source);
            imagedestroy($target);
        }

        return $jpeg;
    }

    public function update(Visitor $visitor, ?UploadedFile $photo, bool $remove, bool $printAllowed): void
    {
        app(HostGuests::class)->editable($visitor, true);
        $old = $visitor->profile_photo_file;
        $path = $old;
        if ($photo) {
            $jpeg = $this->clean($photo->getContent());
            $path = 'profile-portraits/'.Str::uuid().'.enc';
            if (! Storage::disk('local')->put($path, Crypt::encryptString($jpeg))) {
                throw new \RuntimeException('Enregistrement du portrait impossible.');
            }
        } elseif ($remove) {
            $path = null;
        }
        try {
            $visitor->update(['profile_photo_file' => $path, 'profile_photo_print_allowed' => $path !== null && $printAllowed]);
            if ($old && $old !== $path) {
                DB::afterCommit(fn () => $this->deleteFile($old));
            }
            if ($photo || $remove || $visitor->wasChanged('profile_photo_print_allowed')) {
                app(Audit::class)->append('portrait.modifie', auth()->id(), ['visitor_id' => $visitor->id, 'photo_presente' => $path !== null, 'impression_autorisee' => $path !== null && $printAllowed]);
            }
        } catch (\Throwable $error) {
            if ($photo && $path !== $old) {
                $this->deleteFile($path);
            }
            throw $error;
        }
    }

    public function read(Visitor $visitor, bool $printing = false): ?string
    {
        if (! $visitor->profile_photo_file || ($printing && ! $visitor->profile_photo_print_allowed)) {
            return null;
        }
        $this->checkPath($visitor->profile_photo_file);
        $encrypted = Storage::disk('local')->get($visitor->profile_photo_file);
        if ($encrypted === null) {
            return null;
        }
        $bytes = Crypt::decryptString($encrypted);
        app(Audit::class)->append($printing ? 'portrait.impression_lue' : 'portrait.consulte', auth()->id(), ['visitor_id' => $visitor->id]);

        return $bytes;
    }

    public function deleteFile(string $path): void
    {
        $this->checkPath($path);
        if (Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
            throw new \RuntimeException('Suppression du portrait impossible.');
        }
    }

    private function checkPath(string $path): void
    {
        if (preg_match('~^profile-portraits/[0-9a-f-]{36}\.enc$~', $path) !== 1) {
            throw new \RuntimeException('Référence de portrait invalide.');
        }
    }
}
