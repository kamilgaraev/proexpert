<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),
    
    'middleware' => ['web', 'auth:api', 'authorize:notifications.view_analytics'],
    
    'waits' => [
        'redis:notifications-critical' => 30,
        'redis:notifications-high' => 60,
        'redis:notifications' => 120,
        'redis:notifications-low' => 300,
    ],
    
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    
    'silenced' => [
    ],
    
    'fast_termination' => false,
    
    'memory_limit' => 64,
    
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],
    
    'environments' => [
        'production' => [
            'supervisor-critical' => [
                'connection' => 'redis',
                'queue' => ['notifications-critical'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 5,
                'timeout' => 120,
            ],
            'supervisor-high' => [
                'connection' => 'redis',
                'queue' => ['notifications-high'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'tries' => 3,
                'timeout' => 120,
            ],
            'supervisor-normal' => [
                'connection' => 'redis',
                'queue' => ['notifications', 'default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 300,
            ],
            'supervisor-low' => [
                'connection' => 'redis',
                'queue' => ['notifications-low'],
                'balance' => 'simple',
                'processes' => 1,
                'tries' => 2,
                'timeout' => 600,
            ],
            'supervisor-imports' => [
                'connection' => 'redis',
                'queue' => ['imports'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'tries' => 1,
                'timeout' => 1200,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['imports', 'notifications-critical', 'notifications-high', 'notifications', 'notifications-low', 'default'],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
    ],
];
