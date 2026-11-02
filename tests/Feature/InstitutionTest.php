<?php

use App\Models\User;
use App\Services\Institution;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function instUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif identité', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}

function instPng(int $w = 64, int $h = 64): UploadedFile
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 90, 160));
    ob_start();
    imagepng($image);
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    file_put_contents($path, ob_get_clean());

    return new UploadedFile($path, 'logo.png', 'image/png', null, true);
}

function instForm(array $over = []): array
{
    return array_merge([
        'nom' => 'AILCT', 'nom_complet' => 'Académie Internationale de Lutte Contre le Terrorisme', 'slogan' => '',
        'accueil_titre' => '', 'accueil_description' => 'Plateforme d’accueil de l’Académie.', 'connexion_accroche' => '', 'pied' => 'AILCT — usage interne',
        'primary' => '#0C2D66', 'secondary' => '#1F5FBF', 'accent' => '#D6B46B',
    ], $over);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    DB::table('settings')->where('key', 'like', 'institution\\_%')->delete();
    @unlink(app(Institution::class)->logoPath());
});

it('garde l’identité IKOMA ACCESS standard quand rien n’est configuré', function () {
    $profile = app(Institution::class)->profile();
    expect($profile['custom'])->toBeFalse()->and($profile['name'])->toBe('IKOMA ACCESS')->and($profile['logoUrl'])->toBeNull()
        ->and($profile['primary'])->toBe('#0B3C6B')->and($profile['accent'])->toBe('#F75C03')->and($profile['loginGreeting'])->toBe('Bienvenue sur IKOMA ACCESS');
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Auth/Login')->where('institution.custom', false)->where('institution.name', 'IKOMA ACCESS'));
    $this->get('/login')->assertSee('<title>IKOMA ACCESS</title>', false)->assertSee('/icon.svg', false)->assertDontSee('/institution/theme.css', false);
    expect($this->get('/institution/theme.css')->assertOk()->getContent())->toBe('');
    $this->get('/institution/logo')->assertNotFound();
});

it('affiche l’identité personnalisée sur les pages publiques', function () {
    $this->actingAs($admin = instUser('administrateur'))->put('/administration/identite', instForm())->assertRedirect();
    // A06.5 : par défaut, l'accueil d'une installation institutionnelle est désactivé ; on l'active explicitement pour vérifier son contenu public.
    $this->put('/administration/identite/accueil', ['actif' => true])->assertRedirect();
    auth()->logout();
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home')->where('institution.custom', true)->where('institution.name', 'AILCT')->where('institution.primary', '#0C2D66'));
    $this->get('/login')->assertSee('<title>AILCT</title>', false)->assertSee('/institution/theme.css?v=', false)
        ->assertInertia(fn (Assert $p) => $p->where('institution.loginGreeting', 'Bienvenue à AILCT')->where('institution.footer', 'AILCT — usage interne'));
    expect($this->get('/institution/theme.css')->getContent())->toContain('--inst-primary:#0C2D66')->toContain('--color-ikoma-navy:#0C2D66')->toContain('--color-ikoma-orange:#D6B46B');
    expect(DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', 'institution.modifiee')->count())->toBe(1);
});

it('ne personnalise rien tant qu’aucun nom n’est saisi, même si des couleurs sont enregistrées', function () {
    $this->actingAs(instUser('administrateur'))->put('/administration/identite', instForm(['nom' => '']))->assertRedirect();
    $profile = app(Institution::class)->profile();
    expect($profile['custom'])->toBeFalse()->and($profile['primary'])->toBe('#0B3C6B');
    expect($this->get('/institution/theme.css')->getContent())->toBe('');
});

it('refuse une couleur ou un texte invalide', function () {
    $admin = instUser('administrateur');
    $this->actingAs($admin)->put('/administration/identite', instForm(['primary' => 'rouge']))->assertSessionHasErrors('primary');
    $this->put('/administration/identite', instForm(['accent' => '#12345']))->assertSessionHasErrors('accent');
    $this->put('/administration/identite', instForm(['nom' => '<script>alert(1)</script>']))->assertSessionHasErrors('nom');
    $this->put('/administration/identite', instForm(['nom' => str_repeat('A', 41)]))->assertSessionHasErrors('nom');
    expect(app(Institution::class)->profile()['custom'])->toBeFalse();
});

it('ignore une couleur stockée invalide au lieu de casser l’affichage', function () {
    DB::table('settings')->insert([['key' => 'institution_nom', 'value' => 'AILCT'], ['key' => 'institution_couleur_primary', 'value' => 'url(javascript:1)']]);
    expect(app(Institution::class)->profile()['primary'])->toBe('#0B3C6B');
    expect($this->get('/institution/theme.css')->getContent())->not->toContain('javascript');
});

it('réserve l’écran d’identité à l’administrateur', function () {
    $this->get('/administration/identite')->assertRedirect('/login');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $user = instUser($role);
        $this->actingAs($user)->get('/administration/identite')->assertForbidden();
        $this->put('/administration/identite', instForm())->assertForbidden();
        $this->post('/administration/identite/logo', ['logo' => instPng()])->assertForbidden();
        $this->post('/administration/identite/reinitialiser', ['confirmation' => 'RESTAURER IKOMA'])->assertForbidden();
    }
    expect(app(Institution::class)->profile()['custom'])->toBeFalse();
    $this->actingAs(instUser('administrateur'))->get('/administration/identite')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Institution')->where('custom', false));
});

it('enregistre un logo réencodé, le sert sans cookie et peut le retirer', function () {
    $admin = instUser('administrateur');
    $this->actingAs($admin)->put('/administration/identite', instForm())->assertRedirect();
    $this->post('/administration/identite/logo', ['logo' => instPng(900, 600)])->assertRedirect();
    $profile = app(Institution::class)->profile();
    expect($profile['logoUrl'])->toStartWith('/institution/logo?v=');
    [$w, $h] = getimagesize(app(Institution::class)->logoPath());
    expect(max($w, $h))->toBe(512);
    auth()->logout();
    $response = $this->get('/institution/logo')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/png')->and($response->headers->get('Cache-Control'))->toContain('public');
    $names = array_map(fn ($c) => $c->getName(), $response->baseResponse->headers->getCookies());
    expect($names)->not->toContain(config('session.cookie'))->and($names)->not->toContain('XSRF-TOKEN');
    $theme = $this->get('/institution/theme.css');
    expect(array_map(fn ($c) => $c->getName(), $theme->baseResponse->headers->getCookies()))->toBe([]);
    $this->actingAs($admin)->delete('/administration/identite/logo')->assertRedirect();
    $this->get('/institution/logo')->assertNotFound();
});

it('refuse un fichier qui n’est pas une image PNG ou JPEG', function () {
    $this->actingAs(instUser('administrateur'))->put('/administration/identite', instForm());
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
    $this->post('/administration/identite/logo', ['logo' => $svg])->assertSessionHasErrors('logo');
    $tiny = instPng(10, 10);
    $this->post('/administration/identite/logo', ['logo' => $tiny])->assertSessionHasErrors('logo');
    expect(is_file(app(Institution::class)->logoPath()))->toBeFalse();
});

it('restaure l’identité standard sur confirmation et journalise sans donnée personnelle', function () {
    $admin = instUser('administrateur');
    $this->actingAs($admin)->put('/administration/identite', instForm());
    $this->post('/administration/identite/logo', ['logo' => instPng()]);
    $this->post('/administration/identite/reinitialiser', ['confirmation' => 'non'])->assertSessionHasErrors('confirmation');
    expect(app(Institution::class)->profile()['custom'])->toBeTrue();
    $this->post('/administration/identite/reinitialiser', ['confirmation' => 'RESTAURER IKOMA'])->assertRedirect();
    expect(app(Institution::class)->profile()['custom'])->toBeFalse()->and(is_file(app(Institution::class)->logoPath()))->toBeFalse()
        ->and(DB::table('settings')->where('key', 'like', 'institution\\_%')->count())->toBe(0);
    $details = DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', 'like', 'institution.%')->pluck('details')->implode(' ');
    expect($details)->not->toContain('AILCT')->and($details)->not->toContain('Académie');
});

it('ne change ni les rôles, ni les permissions, ni la page de connexion de l’utilisateur', function () {
    $agent = instUser('agent_accueil');
    $before = $agent->getAllPermissions()->pluck('name')->sort()->values()->all();
    $this->actingAs(instUser('administrateur'))->put('/administration/identite', instForm());
    expect($agent->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($before);
});

it('n’est lue par aucun service métier : seuls l’affichage, l’écran d’administration et le nom du contexte permanent y accèdent', function () {
    $allowed = ['Services/Institution.php', 'Http/Controllers/InstitutionController.php', 'Http/Middleware/HandleInertiaRequests.php', 'Providers/AppServiceProvider.php', 'Services/PermanentHolders.php', 'Services/HolderCards.php', 'Http/Controllers/HolderCardsController.php'];
    $readers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'Institution::class')) {
            $readers[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }
    expect(array_diff($readers, $allowed))->toBe([]);
});
