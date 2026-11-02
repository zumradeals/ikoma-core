<?php

use App\Http\Middleware\SecurityHeaders;

/**
 * Régression : le service worker précache ces pages publiques avec les cookies du navigateur. Leur réponse ne doit jamais renvoyer le cookie
 * de session ou de CSRF, sinon une réponse tardive (émise avant la connexion) écrase le cookie authentifié et déconnecte l'utilisateur.
 */
it('ne renvoie aucun cookie de session ou de CSRF sur les pages publiques précachées', function (string $path) {
    $response = $this->get('/'.ltrim($path, '/'))->assertOk();
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->baseResponse->headers->getCookies());

    expect($names)->not->toContain(config('session.cookie'))->and($names)->not->toContain('XSRF-TOKEN');
})->with(SecurityHeaders::PRECACHED_PUBLIC_PATHS);

it('continue d’émettre les cookies de session et de CSRF sur la page de connexion', function () {
    $response = $this->get('/login')->assertOk();
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->baseResponse->headers->getCookies());

    expect($names)->toContain(config('session.cookie'))->and($names)->toContain('XSRF-TOKEN');
});

it('garde la liste des pages précachées alignée sur la configuration du service worker', function () {
    preg_match_all("/\{ url: '([^']+)'/", file_get_contents(base_path('vite.config.ts')), $matches);
    $public = array_values(array_filter($matches[1], fn (string $url) => $url === '/' || str_ends_with($url, '/shell')));

    expect(array_map(fn (string $url) => ltrim($url, '/') ?: '/', $public))->toEqualCanonicalizing(SecurityHeaders::PRECACHED_PUBLIC_PATHS);
});
