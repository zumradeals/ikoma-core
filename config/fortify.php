<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'middleware' => ['web'], 'auth_middleware' => 'auth', 'passwords' => 'users', 'username' => 'email', 'email' => 'email', 'views' => true, 'home' => '/espace', 'prefix' => '', 'domain' => null, 'lowercase_usernames' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true])],
];
