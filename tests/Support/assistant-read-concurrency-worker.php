<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\IsolatedPostgresTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$payloadPath = $argv[1] ?? '';
$temporaryRoot = realpath(sys_get_temp_dir());
$payloadDirectory = realpath(dirname($payloadPath));
if (! is_string($temporaryRoot)
    || ! is_string($payloadDirectory)
    || realpath(dirname($payloadDirectory)) !== $temporaryRoot
    || ! str_starts_with(basename(dirname($payloadPath)), 'most-assistant-read-limiter-')) {
    exit(2);
}

$payload = json_decode((string) file_get_contents($payloadPath), true, 32, JSON_THROW_ON_ERROR);
$connection = $payload['connection'] ?? [];
try {
    IsolatedPostgresTestDatabase::assertSafeConfiguration($connection, 'ai-assistant');
} catch (RuntimeException) {
    exit(2);
}
if (getenv('APP_ENV') !== 'testing'
    || getenv('MOST_POSTGRES_TEST_PROFILE') !== 'ai-assistant'
    || getenv('ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP') !== '1'
    || preg_match('/^most_phpunit_[a-f0-9]+_testing$/D', (string) ($connection['database'] ?? '')) !== 1
    || preg_match('/^most_phpunit_[a-f0-9]{24}$/D', (string) ($connection['search_path'] ?? '')) !== 1
    || ! empty($connection['url'])
    || ! is_string($payload['app_key'] ?? null)
    || $payload['app_key'] === '') {
    exit(2);
}

try {
    $bootstrap = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $bootstrap->useEnvironmentPath(dirname($payloadPath));
    $bootstrap->make(Kernel::class)->bootstrap();
    if (! $bootstrap->environment('testing')) {
        throw new RuntimeException('unsafe_child_environment');
    }
    config()->set('database.connections.ai_read_limiter_test', $connection);
    DB::setDefaultConnection('ai_read_limiter_test');
    config()->set('app.key', $payload['app_key']);
    config()->set('queue.connections.redis_ai_rag.connection', 'default');
    config([
        'database.redis.client' => 'predis',
        'database.redis.default.host' => $payload['redis_host'],
        'database.redis.default.port' => (int) $payload['redis_port'],
        'database.redis.default.url' => null,
        'database.redis.default.password' => null,
        'database.redis.default.username' => null,
        'database.redis.default.database' => 0,
        'database.redis.default.options.parameters.timeout' => 2,
        'database.redis.default.options.parameters.read_write_timeout' => 2,
    ]);
    Redis::purge('default');
    $redis = Redis::connection('default');
    $redis->incr($payload['barrier_key']);
    $barrierDeadline = microtime(true) + 45;
    while ((int) $redis->get($payload['start_key']) !== 1) {
        if (microtime(true) >= $barrierDeadline) {
            throw new RuntimeException('assistant_read_worker_barrier_timeout');
        }
        usleep(10_000);
    }

    $metricsPrefix = $payload['key_prefix'].'metrics:';
    $limiter = new AssistantReadConcurrencyLimiter($payload['key_prefix'].'permits:');
    $runStartedAt = hrtime(true);
    $admissionWaitMs = 0.0;
    $limiter->run(
        static function () use ($redis, $metricsPrefix, $payload, $runStartedAt, &$admissionWaitMs): void {
            $admissionWaitMs = (hrtime(true) - $runStartedAt) / 1_000_000;
            $organizationId = (string) $payload['organization_id'];
            $active = (int) $redis->eval(
                "local active = redis.call('INCR', KEYS[1]); local maximum = tonumber(redis.call('GET', KEYS[2]) or '0'); if active > maximum then redis.call('SET', KEYS[2], active) end; if tonumber(redis.call('GET', KEYS[3]) or '0') > 0 then redis.call('INCR', KEYS[4]) end; local orgActive = redis.call('INCR', KEYS[5]); local orgMaximum = tonumber(redis.call('GET', KEYS[6]) or '0'); if orgActive > orgMaximum then redis.call('SET', KEYS[6], orgActive) end; return active",
                6,
                $metricsPrefix.'read-active',
                $metricsPrefix.'read-max',
                $metricsPrefix.'provider-active',
                $metricsPrefix.'read-provider-overlap',
                $metricsPrefix.'org-active:'.$organizationId,
                $metricsPrefix.'org-max:'.$organizationId,
            );
            if ($active > 2) {
                $redis->incr($metricsPrefix.'read-limit-breaches');
            }
            usleep(((int) $payload['read_hold_ms']) * 1000);
            $redis->decr($metricsPrefix.'read-active');
            $redis->decr($metricsPrefix.'org-active:'.$organizationId);
        },
        static function (): void {
        },
        (string) $payload['organization_id'],
    );

    if ((int) $payload['provider_hold_ms'] > 0) {
        $redis->incr($metricsPrefix.'provider-active');
        usleep(((int) $payload['provider_hold_ms']) * 1000);
        $redis->decr($metricsPrefix.'provider-active');
    }
    echo json_encode([
        'result' => 'ok',
        'admission_wait_ms' => round($admissionWaitMs, 2),
        'total_elapsed_ms' => round((hrtime(true) - $runStartedAt) / 1_000_000, 2),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'result' => 'error',
        'exception_class' => get_class($exception),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
