<?php

declare(strict_types=1);

return [
    'base_url'                     => env('AKAHU_BASE_URL', 'https://api.akahu.io/v1'),
    'app_token'                    => env('AKAHU_APP_TOKEN', ''),
    'user_token'                   => env('AKAHU_USER_TOKEN', ''),
    'internal_account_prefix'      => env('AKAHU_INTERNAL_ACCOUNT_PREFIX', ''),
    'mortgage_payment_pattern'     => env('AKAHU_MORTGAGE_PAYMENT_PATTERN', ''),
    'connection_timeout'           => env('AKAHU_CONNECTION_TIMEOUT', 30),
    'stale_refresh_hours'          => env('AKAHU_STALE_REFRESH_HOURS', 2),
    'refresh_poll_seconds'         => env('AKAHU_REFRESH_POLL_SECONDS', 10),
    'refresh_wait_timeout_seconds' => env('AKAHU_REFRESH_WAIT_TIMEOUT_SECONDS', 180),
    'unique_column_options'        => [
        'external-id' => 'External identifier',
    ],
];
