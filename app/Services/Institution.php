<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Identité visible de l'installation (nom, logo, couleurs, libellés).
 *
 * Sans personnalisation (aucun nom enregistré), le profil est strictement celui d'IKOMA ACCESS : les écrans gardent leur apparence d'origine.
 * Le profil ne décide d'aucun droit, d'aucun contrôle ni d'aucun statut : il n'est lu que par les vues et les gabarits d'affichage.
 * Il est stocké dans la table des réglages (clés institution_*), jamais dans un fichier technique.
 */
final class Institution
{
    public const NAME = 'IKOMA ACCESS';

    public const COLORS = ['primary' => '#0B3C6B', 'secondary' => '#0F5AA6', 'accent' => '#F75C03'];

    /** Champs texte : clé de réglage => longueur maximale. */
    public const TEXTS = [
        'nom' => 40, 'nom_complet' => 120, 'slogan' => 120,
        'accueil_titre' => 120, 'accueil_description' => 400,
        'connexion_accroche' => 80, 'pied' => 160,
    ];

    private const LOGO_MAX_BYTES = 1_048_576;

    private const LOGO_MAX_SIDE = 512;

    /** @var array<string, mixed>|null */
    private ?array $profile = null;

    /** @return array<string, string> */
    private function stored(): array
    {
        return DB::table('settings')->where('key', 'like', 'institution\\_%')->pluck('value', 'key')->all();
    }

    /**
     * @return array{
     *   custom: bool, name: string, fullName: string, tagline: string, logoUrl: ?string, homeTitle: string, homeDescription: string,
     *   loginGreeting: string, footer: string, primary: string, secondary: string, accent: string, version: string, homeEnabled: bool
     * }
     */
    public function profile(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }
        $raw = $this->stored();
        $text = fn (string $key): string => trim((string) ($raw['institution_'.$key] ?? ''));
        $name = $text('nom');
        $custom = $name !== '';
        $color = function (string $key) use ($raw, $custom): string {
            $value = (string) ($raw['institution_couleur_'.$key] ?? '');

            return $custom && self::validColor($value) ? strtoupper($value) : self::COLORS[$key];
        };
        $version = (string) ($raw['institution_version'] ?? '0');

        return $this->profile = [
            'custom' => $custom,
            'name' => $custom ? $name : self::NAME,
            'fullName' => $custom ? ($text('nom_complet') ?: $name) : self::NAME,
            'tagline' => $custom ? $text('slogan') : '',
            'logoUrl' => $custom && ($raw['institution_logo'] ?? '') === '1' && is_file($this->logoPath()) ? '/institution/logo?v='.$version : null,
            'homeTitle' => $custom ? ($text('accueil_titre') ?: ($text('nom_complet') ?: $name)) : '',
            'homeDescription' => $custom ? $text('accueil_description') : '',
            'loginGreeting' => $custom ? ($text('connexion_accroche') ?: 'Bienvenue à '.$name) : 'Bienvenue sur '.self::NAME,
            'footer' => $custom ? $text('pied') : '',
            'primary' => $color('primary'),
            'secondary' => $color('secondary'),
            'accent' => $color('accent'),
            'version' => $version,
            'homeEnabled' => $this->homeEnabled((string) ($raw['institution_accueil'] ?? ''), $custom),
        ];
    }

    /**
     * Données partagées avec les pages : uniquement ce qui est public (la page d'accueil et la connexion sont publiques).
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        $profile = $this->profile();
        unset($profile['version']);

        return $profile;
    }

    /**
     * Valeurs saisies telles qu'enregistrées, pour l'écran d'administration.
     *
     * @return array<string, bool|string>
     */
    public function form(): array
    {
        $raw = $this->stored();
        $out = [];
        foreach (array_keys(self::TEXTS) as $key) {
            $out[$key] = (string) ($raw['institution_'.$key] ?? '');
        }
        $out['accueil_actif'] = $this->profile()['homeEnabled'];
        foreach (array_keys(self::COLORS) as $key) {
            $stored = (string) ($raw['institution_couleur_'.$key] ?? '');
            $out[$key] = self::validColor($stored) ? $stored : self::COLORS[$key];
        }

        return $out;
    }

    /**
     * Page d'accueil publique. Sans réglage explicite : active pour l'installation IKOMA ACCESS standard (comportement historique), désactivée pour une installation
     * institutionnelle (nom configuré). Désactiver n'efface ni contenu ni réglage ; le réglage n'a aucun lien avec l'authentification ni les permissions.
     */
    private function homeEnabled(string $stored, bool $custom): bool
    {
        return match ($stored) {
            '1' => true,
            '0' => false,
            default => ! $custom,
        };
    }

    public static function validColor(string $value): bool
    {
        return preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value) === 1;
    }

    /** Feuille de style publique : redéfinit uniquement les variables de couleur de la marque. */
    public function themeCss(): string
    {
        $p = $this->profile();
        if (! $p['custom']) {
            return '';
        }

        return ":root{--inst-primary:{$p['primary']};--inst-secondary:{$p['secondary']};--inst-accent:{$p['accent']};--inst-accent-ui:{$p['accent']};--inst-accent-alt:{$p['accent']};"
            ."--color-ikoma-navy:{$p['primary']};--color-ikoma-blue:{$p['secondary']};--color-ikoma-orange:{$p['accent']}}\n";
    }

    public function logoPath(): string
    {
        return storage_path('app/private/institution/logo.png');
    }

    /** @param array<string, mixed> $data textes (clés de self::TEXTS) et couleurs (primary, secondary, accent) déjà validés */
    public function save(array $data, User $actor): void
    {
        DB::transaction(function () use ($data, $actor): void {
            foreach (array_keys(self::TEXTS) as $key) {
                $this->put('institution_'.$key, trim((string) ($data[$key] ?? '')));
            }
            foreach (array_keys(self::COLORS) as $key) {
                $value = (string) ($data[$key] ?? '');
                $this->put('institution_couleur_'.$key, self::validColor($value) ? strtoupper($value) : '');
            }
            $this->touch();
            app(Audit::class)->append('institution.modifiee', $actor->id, ['personnalisee' => trim((string) ($data['nom'] ?? '')) !== '']);
        });
        $this->profile = null;
    }

    /** Active ou désactive la page d'accueil publique, de façon explicite. Aucun contenu ni autre réglage n'est modifié ou supprimé. */
    public function setHome(bool $enabled, User $actor): void
    {
        DB::transaction(function () use ($enabled, $actor): void {
            $this->put('institution_accueil', $enabled ? '1' : '0');
            $this->touch();
            app(Audit::class)->append('institution.accueil_modifie', $actor->id, ['actif' => $enabled]);
        });
        $this->profile = null;
    }

    public function storeLogo(UploadedFile $file, User $actor): void
    {
        if ($file->getSize() > self::LOGO_MAX_BYTES) {
            throw ValidationException::withMessages(['logo' => 'Le logo dépasse 1 Mo.']);
        }
        $bytes = (string) file_get_contents($file->getPathname());
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($type, ['image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages(['logo' => 'Le logo doit être une image PNG ou JPEG.']);
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false || imagesx($source) < 32 || imagesy($source) < 32 || imagesx($source) > 4000 || imagesy($source) > 4000) {
            throw ValidationException::withMessages(['logo' => 'Image illisible ou de dimensions non acceptées (32 à 4 000 pixels).']);
        }
        $ratio = min(1, self::LOGO_MAX_SIDE / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $ratio));
        $height = max(1, (int) round(imagesy($source) * $ratio));
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        // Réencodage : aucune métadonnée ni contenu ajouté de l'image d'origine n'est conservé.
        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        File::ensureDirectoryExists(dirname($this->logoPath()), 0700, true);
        file_put_contents($this->logoPath(), $png);
        chmod($this->logoPath(), 0600);
        DB::transaction(function () use ($actor): void {
            $this->put('institution_logo', '1');
            $this->touch();
            app(Audit::class)->append('institution.logo_modifie', $actor->id, []);
        });
        $this->profile = null;
    }

    public function removeLogo(User $actor): void
    {
        File::delete($this->logoPath());
        DB::transaction(function () use ($actor): void {
            $this->put('institution_logo', '0');
            $this->touch();
            app(Audit::class)->append('institution.logo_retire', $actor->id, []);
        });
        $this->profile = null;
    }

    /** Retour à l'identité IKOMA ACCESS standard. */
    public function reset(User $actor): void
    {
        File::delete($this->logoPath());
        DB::transaction(function () use ($actor): void {
            DB::table('settings')->where('key', 'like', 'institution\\_%')->delete();
            app(Audit::class)->append('institution.reinitialisee', $actor->id, []);
        });
        $this->profile = null;
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
    }

    private function touch(): void
    {
        $this->put('institution_version', (string) now()->timestamp);
    }
}
