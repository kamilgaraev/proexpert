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

function application(array $data, Request $request, array &$bootstrapProfile = [], ?array &$stageContext = null): Application
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
    if ($stageContext !== null) {
        $app['config']->set('broadcasting.connections.reverb.client_options', reverbClientOptions(
            $app['config']->get('broadcasting.connections.reverb.client_options', []), $stageContext,
        ));
    }
    $bootstrapProfile['test_storage_setup_ms'] = round((hrtime(true) - $storageStarted) / 1000000, 2);

    return $app;
}

function appendPrivateRecord(array $data, string $name, array $record): void
{
    $stream = null;
    try {
        $directory = realpath($data['runtime_directory'] ?? '');
        $temporary = realpath(sys_get_temp_dir());
        if ($directory === false || $temporary === false || dirname($directory) !== $temporary
            || preg_match('/^most-bim-device-[a-f0-9]{24}$/D', basename($directory)) !== 1
            || ! in_array($name, ['stages.jsonl', 'exceptions.jsonl'], true) || is_link($directory.'/'.$name)) {
            return;
        }
        $stream = fopen($directory.'/'.$name, 'c+b');
        if ($stream === false || ! flock($stream, LOCK_EX)) {
            return;
        }
        $encoded = json_encode($record, JSON_THROW_ON_ERROR)."\n";
        $size = fstat($stream)['size'] ?? 1048576;
        if ($size + strlen($encoded) > 1048576) {
            return;
        }
        fseek($stream, 0, SEEK_END);
        fwrite($stream, $encoded);
        fflush($stream);
    } catch (\Throwable) {
    } finally {
        if (is_resource($stream)) {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}

function beginStages(array $data, string $method, string $path): ?array
{
    $environment = $data['environment'] ?? [];
    $directory = realpath($data['runtime_directory'] ?? '');
    $temporary = realpath(sys_get_temp_dir());
    $path = parse_url($path, PHP_URL_PATH);
    if (($data['schema_version'] ?? null) !== 1 || ($data['expires_at'] ?? 0) < time()
        || ($environment['APP_ENV'] ?? null) !== 'testing' || ($environment['DB_CONNECTION'] ?? null) !== 'pgsql'
        || ($environment['DB_HOST'] ?? null) !== '127.0.0.1' || (string) ($environment['DB_PORT'] ?? '') !== '55433'
        || preg_match('/^most_phpunit_[a-f0-9]{24}_testing$/D', $environment['DB_DATABASE'] ?? '') !== 1
        || ! empty($environment['DB_URL']) || $directory === false || $temporary === false
        || dirname($directory) !== $temporary || preg_match('/^most-bim-device-[a-f0-9]{24}$/D', basename($directory)) !== 1
        || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)
        || ! is_string($path) || preg_match('#^/api/v1/(?:admin|mobile)/[A-Za-z0-9/_-]{1,500}$#D', $path) !== 1) {
        return null;
    }
    $context = ['runtime_directory' => $directory, 'started' => hrtime(true), 'state' => (object) ['last_marker' => null],
        'request_id' => bin2hex(random_bytes(12)), 'method' => $method, 'path' => $path];
    stage($context, 'application_start');
    register_shutdown_function(static function () use (&$context): void {
        $error = error_get_last();
        $fatal = is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $source = $fatal ? str_replace('\\', '/', $error['file']) : '';
        $root = str_replace('\\', '/', dirname(__DIR__, 3)).'/';
        stage($context, 'shutdown', ['last_marker' => $context['state']->last_marker,
            'fatal_type' => $fatal ? $error['type'] : null,
            'fatal_file' => str_starts_with($source, $root) ? substr($source, strlen($root)) : null,
            'fatal_line' => $fatal ? $error['line'] : null, ...classifyFailure($fatal ? $error['message'] : '')]);
    });

    return $context;
}

function stage(?array &$context, string $marker, array $details = []): void
{
    $markers = ['application_start', 'bootstrap_done', 'kernel_start', 'kernel_end',
        'broadcast_start', 'broadcast_end', 'broadcast_failure', 'shutdown'];
    if ($context === null || ! in_array($marker, $markers, true)) {
        return;
    }
    $record = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'request_id' => $context['request_id'], 'stage' => $marker,
        'pid' => getmypid(), 'max_execution_time' => (int) ini_get('max_execution_time'),
        'elapsed_ms' => round((hrtime(true) - $context['started']) / 1000000, 2),
        'method' => $context['method'], 'path' => $context['path']];
    if (isset($details['status_code']) && is_int($details['status_code'])) {
        $record['status_code'] = $details['status_code'];
    }
    if (isset($details['exception_class']) && is_string($details['exception_class'])
        && preg_match('/^[A-Za-z0-9_\\\\]{1,200}$/D', $details['exception_class']) === 1) {
        $record['exception_class'] = $details['exception_class'];
    }
    if ($marker === 'shutdown') {
        $record['last_marker'] = in_array($details['last_marker'] ?? null, $markers, true) ? $details['last_marker'] : null;
        $record['fatal_type'] = $details['fatal_type'] ?? null;
        $record['fatal_line'] = $details['fatal_line'] ?? null;
        $source = $details['fatal_file'] ?? null;
        $record['fatal_file'] = is_string($source) && ! str_contains($source, '..')
            && preg_match('#^(?:app|bootstrap|config|routes|vendor|tests/Runtime/bim-device-acceptance)/[A-Za-z0-9_./-]{1,500}$#D', $source) === 1 ? $source : null;
        $record['classifier'] = in_array($details['classifier'] ?? null, ['maximum_execution_time', 'memory_exhausted'], true) ? $details['classifier'] : null;
        $record['limits'] = [];
        foreach (['limit_seconds', 'limit_bytes', 'allocation_bytes'] as $key) {
            if (isset($details['limits'][$key]) && is_int($details['limits'][$key])) {
                $record['limits'][$key] = $details['limits'][$key];
            }
        }
    }
    $context['state']->last_marker = $marker;
    appendPrivateRecord($context, 'stages.jsonl', $record);
}

function reverbClientOptions(array $options, ?array &$context): array
{
    if ($context === null) {
        return $options;
    }
    $middleware = static function (callable $handler) use (&$context): callable {
        return static function (\Psr\Http\Message\RequestInterface $request, array $requestOptions) use ($handler, &$context): \GuzzleHttp\Promise\PromiseInterface {
            stage($context, 'broadcast_start');
            try {
                $promise = $handler($request, $requestOptions);
            } catch (\Throwable $exception) {
                stage($context, 'broadcast_failure', ['exception_class' => $exception::class]);
                throw $exception;
            }

            return $promise->then(static function ($response) use (&$context) {
                stage($context, 'broadcast_end', $response instanceof \Psr\Http\Message\ResponseInterface
                    ? ['status_code' => $response->getStatusCode()] : []);

                return $response;
            }, static function ($reason) use (&$context): \GuzzleHttp\Promise\RejectedPromise {
                stage($context, 'broadcast_failure', ['exception_class' => is_object($reason) ? $reason::class : get_debug_type($reason)]);

                return new \GuzzleHttp\Promise\RejectedPromise($reason);
            });
        };
    };
    $handler = $options['handler'] ?? \GuzzleHttp\HandlerStack::create();
    if ($handler instanceof \GuzzleHttp\HandlerStack) {
        $handler = clone $handler;
        $handler->unshift($middleware, 'bim_acceptance_stages');
        $options['handler'] = $handler;
    } elseif (is_callable($handler)) {
        $options['handler'] = $middleware($handler);
    }

    return $options;
}

function classifyFailure(string $message): array
{
    if (preg_match('/^Maximum execution time of ([0-9]{1,18}) seconds exceeded(?:\b|$)/', $message, $matches) === 1) {
        return ['classifier' => 'maximum_execution_time', 'limits' => ['limit_seconds' => (int) $matches[1]]];
    }
    if (preg_match('/^Allowed memory size of ([0-9]{1,18}) bytes exhausted.*tried to allocate ([0-9]{1,18}) bytes/', $message, $matches) === 1) {
        return ['classifier' => 'memory_exhausted', 'limits' => [
            'limit_bytes' => (int) $matches[1], 'allocation_bytes' => (int) $matches[2],
        ]];
    }

    return ['classifier' => null, 'limits' => []];
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
        appendPrivateRecord($data, 'exceptions.jsonl', ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'class' => $exception::class,
            'message' => $safeMessage, 'origin_file' => str_starts_with($origin, $root) ? substr($origin, strlen($root)) : null,
            'origin_line' => $exception->getLine(), 'own_frames' => $frames,
            ...classifyFailure($exception->getMessage())]);
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
