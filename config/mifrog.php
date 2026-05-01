<?php

return [
    'memory' => [
        'base_path' => storage_path('app/memory'),
        'retention_days' => env('MIFROG_RETENTION_DAYS', 180),
    ],
    'skills' => [
        'base_path' => storage_path('app/skills'),
        'allowed_runtimes' => ['python3', 'bash'],
        'http_api' => [
            // Hosts and CIDR-like prefixes that http_api skills must never reach.
            // Covers loopback, link-local metadata, and IPv6 loopback. Operators may
            // additionally blacklist internal network segments here.
            'url_blacklist' => [
                '127.0.0.1',
                'localhost',
                '0.0.0.0',
                '::1',
                '169.254.',          // link-local / cloud metadata (prefix match)
                'metadata.google.internal',
            ],
            // Only http / https schemes are allowed.
            'allowed_schemes' => ['http', 'https'],
            'default_timeout' => 10,
            'max_timeout' => 60,
            // Hard cap on response body size we will pass into the LLM.
            'max_response_bytes' => 64 * 1024,
        ],
    ],
    'prompt' => [
        'use_composer' => env('MIFROG_PROMPT_USE_COMPOSER', true),
    ],
];
