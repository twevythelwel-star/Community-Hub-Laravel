<?php

use App\Models\User;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Seed password
    |---------------------------------------------------------------------------
    |
    | Default used by the local-only `users:set-passwords` helper. It lives in
    | config rather than being read from env() at the call site, because env()
    | returns null once config is cached.
    |
    */

    'seed_password' => env('SEED_PASSWORD', 'ChangeMe!2026'),

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
