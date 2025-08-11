<?php

return [

    'default' => env('BROADCAST_DRIVER', 'log'),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            // Build options safely; only set host/port/scheme when using a self-hosted server.
            'options' => array_filter([
                'host' => env('PUSHER_HOST') ?: null,
                'port' => env('PUSHER_PORT') !== null ? (int) env('PUSHER_PORT') : null,
                'scheme' => env('PUSHER_SCHEME') ?: null,
                // For Pusher Cloud, use cluster and TLS
                'useTLS' => filter_var(env('PUSHER_USE_TLS', true), FILTER_VALIDATE_BOOL),
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            ], fn ($v) => !is_null($v)),
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
