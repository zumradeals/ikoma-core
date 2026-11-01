<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'middleware' => ['web'], 'auth_middleware' => 'auth', 'passwords' => 'users', 'username' => 'email', 'email' => 'email', 'views' => true, 'home' => '/espace', 'prefix' => '', 'domain' => null, 'lowercase_usernames' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    // Deployment policy (default ON). The same Fortify feature flag drives both
    // the login challenge and the application's role-based enforcement.
    // Existing 2FA secrets/recovery codes are preserved when OFF.
    'features' => env('IKOMA_2FA_ENABLED', true)
        ? [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])]
        : [],
];
