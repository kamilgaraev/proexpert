<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Formatter\JsonFormatter;
use App\Services\Logging\EnsureLogFailuresAreNonFatal;

return [

    'log_api_bodies' => env('LOG_API_BODIES', false),

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'deprecations'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'sentry'],
            'ignore_exceptions' => true,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'api' => [
            'driver' => 'daily',
            'path' => storage_path('logs/api/api.log'),
            'level' => env('LOG_API_LEVEL', 'warning'),
            'days' => env('LOG_API_DAYS', 14),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s',
            ],
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],
        
        'telemetry' => [
            'driver' => 'daily',
            'path' => storage_path('logs/telemetry/telemetry.log'),
            'level' => 'info',
            'days' => 7,
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s',
            ],
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        // ProHelper Structured Logging Channels
        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/audit/audit.log'),
            'level' => 'info',
            'days' => env('LOG_AUDIT_DAYS', 365),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s.u',
                'includeStacktraces' => false,
            ],
            'permission' => 0644,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'business' => [
            'driver' => 'daily',
            'path' => storage_path('logs/business/business.log'),
            'level' => 'info',
            'days' => env('LOG_BUSINESS_DAYS', 90),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s.u',
            ],
            'permission' => 0644,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/security/security.log'),
            'level' => 'warning',
            'days' => env('LOG_SECURITY_DAYS', 180),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s.u',
            ],
            'permission' => 0640, // More restrictive permissions
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'technical' => [
            'driver' => 'daily', 
            'path' => storage_path('logs/technical/technical.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => env('LOG_TECHNICAL_DAYS', 30),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s.u',
            ],
            'permission' => 0644,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'access' => [
            'driver' => 'daily',
            'path' => storage_path('logs/access/access.log'), 
            'level' => 'info',
            'days' => env('LOG_ACCESS_DAYS', 30),
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'dateFormat' => 'Y-m-d H:i:s.u',
            ],
            'permission' => 0644,
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'deprecations' => [
            'driver' => 'daily',
            'path' => storage_path('logs/deprecations.log'),
            'level' => 'warning',
            'days' => env('LOG_DEPRECATIONS_DAYS', 14),
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'redis' => [
            'driver' => 'daily',
            'path' => storage_path('logs/redis/redis.log'),
            'level' => env('LOG_REDIS_LEVEL', 'warning'),
            'days' => env('LOG_REDIS_DAYS', 14),
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'database' => [
            'driver' => 'daily',
            'path' => storage_path('logs/database/database.log'),
            'level' => env('LOG_DATABASE_LEVEL', 'warning'),
            'days' => env('LOG_DATABASE_DAYS', 14),
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'auth' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auth/auth.log'),
            'level' => env('LOG_AUTH_LEVEL', 'warning'),
            'days' => env('LOG_AUTH_DAYS', 30),
            'tap' => [EnsureLogFailuresAreNonFatal::class],
        ],

        'sentry' => [
            'driver' => 'sentry',
            'level' => 'error',
        ],

    ],

];
