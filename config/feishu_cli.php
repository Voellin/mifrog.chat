<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feishu CLI Gateway
    |--------------------------------------------------------------------------
    |
    | mifrog routes Feishu OpenAPI bot calls through lark-cli. User API calls
    | always go through Guzzle HTTP for reliable bearer token auth.
    |
    | The binary can be globally installed (`lark-cli`) or pointed to an
    | absolute path via FEISHU_CLI_BIN.
    |
    */
    'enabled' => env('FEISHU_CLI_ENABLED', true),
    'binary' => env('FEISHU_CLI_BIN', 'lark-cli'),
    'config_root' => env('FEISHU_CLI_CONFIG_ROOT', storage_path('app/feishu_cli')),
    'timeout_seconds' => (int) env('FEISHU_CLI_TIMEOUT_SECONDS', 35),

    /*
    |--------------------------------------------------------------------------
    | Concurrency Control
    |--------------------------------------------------------------------------
    |
    | Maximum number of concurrent CLI processes allowed. Each API call forks
    | a lark-cli subprocess; this limit prevents resource exhaustion under load.
    | Uses file-lock based semaphore (no Redis dependency).
    |
    */
    'max_concurrent_processes' => (int) env('FEISHU_CLI_MAX_CONCURRENT', 10),
];
