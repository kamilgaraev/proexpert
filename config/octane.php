<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | Supported: "roadrunner", "swoole"
    |
    */

    'server' => env('OCTANE_SERVER', 'roadrunner'),

    /*
    |--------------------------------------------------------------------------
    | Server Options
    |--------------------------------------------------------------------------
    |
    | These options customize the behavior of the underlying high-performance
    | server. Feel free to tweak them to better fit your infrastructure.
    |
    */

    'workers' => env('OCTANE_WORKERS', null),

    'task_workers' => env('OCTANE_TASK_WORKERS', 1),

    'max_requests' => env('OCTANE_MAX_REQUESTS', 2500),

    'max_request_timeout' => env('OCTANE_MAX_REQUEST_TIMEOUT', 30),

    'garbage' => 2000,

    /*
    |--------------------------------------------------------------------------
    | File Watching
    |--------------------------------------------------------------------------
    |
    | When running Octane in development mode you may enable file watching so
    | that the server automatically reloads when your code changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config',
        'routes',
        'resources',
        'database/migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Cache Table (Swoole only)
    |--------------------------------------------------------------------------
    |
    | You may define Swoole tables here that will be created and managed by
    | Octane. These tables can then be accessed via the Octane facade.
    |
    */

    'tables' => [
        // 'examples' => [ 'size' => 1024, 'columns' => [ ['id' => "string", 'size' => 36], ['data' => "string", 'size' => 512] ] ],
    ],

]; 