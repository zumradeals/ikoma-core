<?php

return [
    'guard' => 'web', 'middleware' => ['web'], 'auth_middleware' => 'auth', 'passwords' => 'users', 'username' => 'email', 'email' => 'email', 'views' => true, 'home' => '/espace', 'prefix' => '', 'domain' => null, 'lowercase_usernames' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    // Client decision: 2FA is disabled on all accounts. Login still requires
    // the password, active status and role authorization.
    'features' => [],
];
