<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain & Path
    |--------------------------------------------------------------------------
    |
    | Defines the domain and URI path where Laravel Horizon is accessible.
    |
    */

    'name' => env('HORIZON_NAME', 'Community Hub Horizon'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | The name of the Redis connection where Horizon stores runtime metadata,
    | supervisors, failed jobs, job metrics, and queue snapshots.
    |
    */

    'use' => env('HORIZON_REDIS_CONNECTION', 'default'),

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'community-hub'), '_').'_horizon:'
    ),

    // System Admin only (`operatePlatform`, as for /operations/queues): failed
    // job payloads carry email and SMS content, and the API retries jobs.
    'middleware' => ['web', 'auth', 'active', 'can:operatePlatform'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | Threshold in seconds for triggering wait time alert notifications.
    |
    */

    'waits' => [
        'redis:high' => 30,
        'redis:default' => 60,
        'redis:low' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Minutes to retain recent and failed job records in Redis.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration (Supervisors & Priorities)
    |--------------------------------------------------------------------------
    |
    | Prioritizes 'high' queue (SMS, Webhooks, Notifications) first,
    | followed by 'default' (Emails, PDF, Images), and 'low' (Exports, Reports, AI).
    |
    */

    'defaults' => [
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'minProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 4,
            'minProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-batch' => [
            'connection' => 'redis',
            'queue' => ['low'],
            'balance' => 'simple',
            'maxProcesses' => 2,
            'minProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 2,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-high' => [
                'maxProcesses' => 12,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-default' => [
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-batch' => [
                'maxProcesses' => 4,
            ],
        ],

        'local' => [
            'supervisor-high' => [
                'maxProcesses' => 2,
            ],
            'supervisor-default' => [
                'maxProcesses' => 2,
            ],
            'supervisor-batch' => [
                'maxProcesses' => 1,
            ],
        ],

        'testing' => [
            'supervisor-high' => [
                'maxProcesses' => 1,
            ],
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
            'supervisor-batch' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
