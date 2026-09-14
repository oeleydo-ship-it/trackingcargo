<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'name' => env('HORIZON_NAME', 'CargoFlow'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'cargoflow'), '_').'_horizon:'),
    'middleware' => ['web', 'auth'],
    'waits' => ['redis:default' => 60],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => true,
    'memory_limit' => 128,
    'defaults' => [
        'operations' => [
            'connection' => 'redis',
            'queue' => ['default', 'shipments', 'tracking', 'delivery', 'location'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        'side-effects' => [
            'connection' => 'redis',
            'queue' => ['notifications', 'documents', 'billing', 'webhooks', 'imports', 'exports'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 300,
            'nice' => 5,
        ],
    ],
    'environments' => [
        'production' => [
            'operations' => ['minProcesses' => 2, 'maxProcesses' => 20, 'balanceMaxShift' => 2, 'balanceCooldown' => 3],
            'side-effects' => ['minProcesses' => 1, 'maxProcesses' => 10, 'balanceMaxShift' => 1, 'balanceCooldown' => 5],
        ],
        'local' => [
            'operations' => ['maxProcesses' => 2],
            'side-effects' => ['maxProcesses' => 1],
        ],
    ],
];
