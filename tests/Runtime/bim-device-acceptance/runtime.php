<?php

declare(strict_types=1);

namespace Tests\Runtime\BimDeviceAcceptance;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';
require_once __DIR__.'/LocalAcceptanceDisk.php';

function descriptor(): array
{
    $path = (string) getenv('BIM_DEVICE_ACCEPTANCE_DESCRIPTOR');
    $root = realpath(dirname($path));
    $temporary = realpath(sys_get_temp_dir());
    if ($root === false || $temporary === false || dirname($root) !== $temporary
        || preg_match('/^most-bim-device-[a-f0-9]{24}$/D', basename($root)) !== 1
        || basename($path) !== 'descriptor.json') {
        throw new RuntimeException('bim_acceptance_descriptor_path_unsafe');
    }
    $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $env = $data['environment'];
    if ($env['APP_ENV'] !== 'testing' || $env['DB_CONNECTION'] !== 'pgsql'
        || $env['DB_HOST'] !== '127.0.0.1' || $env['DB_PORT'] !== '55433'
        || preg_match('/^most_phpunit_[a-f0-9]{24}_testing$/D', $env['DB_DATABASE']) !== 1
        || $env['DB_USERNAME'] !== 'most_testing' || $env['DB_PASSWORD'] !== 'most_testing_password'
        || $data['runtime_directory'] !== $root || time() >= $data['expires_at']
        || $data['ui_origin'] !== 'http://127.0.0.1:31391'
        || $env['WEB_AUTH_ADMIN_ALLOWED_ORIGINS'] !== $data['ui_origin']) {
        throw new RuntimeException('bim_acceptance_descriptor_unsafe_or_expired');
    }
    if (array_key_exists('control_port', $data) || array_key_exists('control_base_url', $data)) {
        $controlPort = $data['control_port'] ?? null;
        $controlBase = $data['control_base_url'] ?? null;
        $ports = [$data['http_port'], $data['admin_http_port'], $data['reverb_port'], $data['redis_port'],
            ...$data['admin_worker_ports'], $controlPort];
        if (! is_int($controlPort) || $controlPort < 1 || $controlPort > 65535
            || $controlBase !== 'http://127.0.0.1:'.$controlPort
            || ($data['device_config']['BIM_API_CONTROL_BASE_URL'] ?? null) !== $controlBase
            || ! is_string($data['device_config']['BIM_API_CONTROL_URL'] ?? null)
            || ! str_starts_with($data['device_config']['BIM_API_CONTROL_URL'], $controlBase.'/__bim_acceptance/control?signature=')
            || count($ports) !== 9 || count(array_unique($ports, SORT_REGULAR)) !== 9) {
            throw new RuntimeException('bim_acceptance_control_resource_invalid');
        }
        foreach ($ports as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535) {
                throw new RuntimeException('bim_acceptance_control_resource_invalid');
            }
        }
    }

    return $data;
}

function application(array $data, Request $request, array &$bootstrapProfile = []): Application
{
    $profileStarted = hrtime(true);
    foreach ($data['environment'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.bim-device-acceptance-does-not-exist');
    $app->useStoragePath($data['runtime_directory'].'/storage');
    $app->instance('request', $request);
    $bootstrapProfile = ['application_setup_ms' => round((hrtime(true) - $profileStarted) / 1000000, 2),
        'stages_ms' => [], 'providers_ms' => [], 'db_query_count' => 0, 'db_ms' => 0.0, 'provider_db_ms' => []];
    $stageStarted = [];
    $providerStarted = [];
    $providerTimings = [];
    $activeProvider = null;
    $profilingBootstrap = true;
    foreach ([\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        \Illuminate\Foundation\Bootstrap\BootProviders::class] as $bootstrapper) {
        $app->beforeBootstrapping($bootstrapper, static function () use (&$stageStarted, $bootstrapper): void {
            $stageStarted[$bootstrapper] = hrtime(true);
        });
        $app->afterBootstrapping($bootstrapper, static function () use (&$stageStarted, &$bootstrapProfile, $bootstrapper): void {
            $bootstrapProfile['stages_ms'][$bootstrapper] = round((hrtime(true) - $stageStarted[$bootstrapper]) / 1000000, 2);
        });
    }
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        static function (Application $application) use (&$providerStarted, &$providerTimings, &$activeProvider, &$profilingBootstrap, &$bootstrapProfile): void {
            $application->make('db')->listen(static function (QueryExecuted $event) use (&$profilingBootstrap, &$activeProvider, &$bootstrapProfile): void {
                if (! $profilingBootstrap) {
                    return;
                }
                $bootstrapProfile['db_query_count']++;
                $bootstrapProfile['db_ms'] += $event->time;
                if ($activeProvider !== null) {
                    $bootstrapProfile['provider_db_ms'][$activeProvider] = ($bootstrapProfile['provider_db_ms'][$activeProvider] ?? 0) + $event->time;
                }
            });
            foreach ($application->getProviders(ServiceProvider::class) as $provider) {
                $providerClass = $provider::class;
                $provider->booting(static function () use (&$providerStarted, &$activeProvider, $providerClass): void {
                    $activeProvider = $providerClass;
                    $providerStarted[$providerClass] = hrtime(true);
                });
                $provider->booted(static function () use (&$providerStarted, &$providerTimings, &$activeProvider, $providerClass): void {
                    $providerTimings[$providerClass] = round((hrtime(true) - $providerStarted[$providerClass]) / 1000000, 2);
                    $activeProvider = null;
                });
            }
        });
    $kernelStarted = hrtime(true);
    $kernel = $app->make(Kernel::class);
    $bootstrapProfile['kernel_resolve_ms'] = round((hrtime(true) - $kernelStarted) / 1000000, 2);
    $kernel->bootstrap();
    $profilingBootstrap = false;
    arsort($providerTimings);
    $bootstrapProfile['providers_ms'] = array_slice($providerTimings, 0, 10, true);
    $bootstrapProfile['db_ms'] = round($bootstrapProfile['db_ms'], 2);
    $storageStarted = hrtime(true);
    $app['config']->set('cache.stores.file.path', $data['runtime_directory'].'/cache');
    $app['config']->set('cache.stores.file.lock_path', $data['runtime_directory'].'/cache');
    $app['config']->set('design_management.session_cache_store', 'redis');
    $app['config']->set('database.redis.client', 'predis');
    foreach (['default', 'cache'] as $connection) {
        $app['config']->set('database.redis.'.$connection, [
            'host' => '127.0.0.1', 'port' => (int) $data['redis_port'],
            'database' => $connection === 'cache' ? 1 : 0, 'password' => null,
            'timeout' => 2, 'read_write_timeout' => 2,
        ]);
    }
    configureStorage($app, $data);
    $bootstrapProfile['test_storage_setup_ms'] = round((hrtime(true) - $storageStarted) / 1000000, 2);

    return $app;
}

function recordException(array $data, \Throwable $exception): void
{
    try {
        $file = $data['runtime_directory'].'/exceptions.jsonl';
        if (is_file($file) && filesize($file) >= 1048576) {
            return;
        }
        $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/';
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            $source = str_replace('\\', '/', $frame['file'] ?? '');
            if (! str_starts_with($source, $root)) {
                continue;
            }
            $relative = substr($source, strlen($root));
            if (! str_starts_with($relative, 'app/') && ! str_starts_with($relative, 'tests/Runtime/bim-device-acceptance/')) {
                continue;
            }
            $frames[] = ['file' => $relative, 'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null];
            if (count($frames) === 8) {
                break;
            }
        }
        $origin = str_replace('\\', '/', $exception->getFile());
        $safeMessage = str_starts_with($exception->getMessage(), 'Error while reading line from the server.')
            ? 'Error while reading line from the server.' : null;
        file_put_contents($file, json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z'), 'class' => $exception::class,
            'message' => $safeMessage, 'origin_file' => str_starts_with($origin, $root) ? substr($origin, strlen($root)) : null,
            'origin_line' => $exception->getLine(), 'own_frames' => $frames], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable) {
    }
}

function configureStorage(Application $app, array $data): void
{
    $app['config']->set('filesystems.disks.s3', [
        'driver' => 'bim-acceptance-local', 'root' => $data['runtime_directory'].'/files',
        'bucket' => 'bim-acceptance-only', 'organization_prefix' => 'org-'.$data['organization_id'].'/',
        'throw' => true,
    ]);
    Storage::extend('bim-acceptance-local', static function (Application $app, array $config) use ($data): LocalAcceptanceDisk {
        $local = $app->make(FilesystemManager::class)->createLocalDriver($config);
        $disk = new LocalAcceptanceDisk($local->getDriver(), $local->getAdapter(), $config);
        $disk->buildTemporaryUrlsUsing(function (string $path, \DateTimeInterface $expires) use ($data): string {
            if (! str_starts_with($path, 'org-'.$data['organization_id'].'/') || str_contains($path, '..') || str_contains($path, '\\')) {
                throw new RuntimeException('bim_acceptance_storage_path_invalid');
            }
            $until = min($expires->getTimestamp(), $data['expires_at']);
            $signature = hash_hmac('sha256', $path.'|'.$until, $data['file_signing_key']);

            return $data['base_url'].'/__bim_acceptance/file?'.http_build_query(['path' => $path, 'expires' => $until, 'signature' => $signature]);
        });

        return $disk;
    });
    Storage::forgetDisk('s3');
}
