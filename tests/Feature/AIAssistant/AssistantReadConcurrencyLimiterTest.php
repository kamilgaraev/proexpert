<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tests\Support\IsolatedPostgresTestDatabase;

final class AssistantReadConcurrencyLimiterTest extends TestCase
{
    private ?Application $app = null;
    private string $runtimeDirectory;
    private string $appKey;
    private int $redisPort;
    /** @var array<string, mixed> */
    private array $isolatedConnection;
    private int $workerSequence = 0;

    /** @var array<string, array{environment: string|false, env: string|false, server: string|false}> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-assistant-read-limiter-'.bin2hex(random_bytes(10));
        mkdir($this->runtimeDirectory);
        $this->setTestEnvironment([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'no-config-cache',
            'MOST_POSTGRES_TEST_PROFILE' => 'ai-assistant',
            'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '1',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) IsolatedPostgresTestDatabase::profilePort('ai-assistant'),
            'DB_URL' => '',
            'REDIS_CLIENT' => 'predis',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '1',
            'REDIS_URL' => '',
            'REDIS_DB' => '0',
        ]);
        $this->isolatedConnection = IsolatedPostgresTestDatabase::configuration();
        $this->setTestEnvironment([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $this->isolatedConnection['host'],
            'DB_PORT' => (string) $this->isolatedConnection['port'],
            'DB_DATABASE' => (string) $this->isolatedConnection['database'],
            'DB_USERNAME' => (string) $this->isolatedConnection['username'],
            'DB_PASSWORD' => (string) $this->isolatedConnection['password'],
            'DB_SCHEMA' => (string) $this->isolatedConnection['search_path'],
            'DB_URL' => '',
        ]);
        IsolatedPostgresTestDatabase::assertSafeConfiguration($this->isolatedConnection, 'ai-assistant');
        $this->appKey = (string) getenv('APP_KEY');
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->useEnvironmentPath($this->runtimeDirectory);
        $this->app->make(Kernel::class)->bootstrap();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Redis::purge('default');
        Facade::clearResolvedInstances();
        HandleExceptions::flushState($this);
        $this->app = null;
        if (isset($this->runtimeDirectory) && is_dir($this->runtimeDirectory)) {
            foreach (glob($this->runtimeDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($this->runtimeDirectory);
        }
        foreach ($this->originalEnvironment as $key => $previous) {
            $previous['environment'] === false ? putenv($key) : putenv($key.'='.$previous['environment']);
            if ($previous['env'] === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previous['env'];
            }
            if ($previous['server'] === false) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previous['server'];
            }
        }
        parent::tearDown();
    }

    public function test_shared_redis_permits_bound_1_5_and_25_process_bursts_and_release_before_provider_wait(): void
    {
        $server = $this->startRedisContainer();
        $keyPrefix = 'ai-read-test:'.bin2hex(random_bytes(12)).':';

        try {
            $this->redisPort = $server['port'];
            $this->configureRedis($server['port']);
            $redis = Redis::connection('default');
            $this->waitUntilRedisReady($redis);

            foreach ([1, 5, 25] as $workerCount) {
                $this->runBurst($server['port'], $keyPrefix.$workerCount.':', $workerCount);
            }

            $this->assertFailureAndCancellationReleasePermits($server['port'], $keyPrefix.'semantics:');
        } finally {
            $this->stopRedisContainer($server['id'], $server['name']);
            Redis::purge('default');
        }
    }

    /** @return array{id: string, name: string, port: int} */
    private function startRedisContainer(): array
    {
        $name = 'most-ai-assistant-redis-test-'.bin2hex(random_bytes(8));
        $run = $this->command([
            'docker', 'run', '--detach', '--rm', '--name', $name,
            '--publish', '127.0.0.1::6379', 'redis:7-alpine',
        ]);
        if ($run['exit_code'] !== 0 || preg_match('/^[a-f0-9]{12,64}$/i', trim($run['stdout'])) !== 1) {
            throw new RuntimeException('assistant_read_limiter_redis_container_start_failed:'.$run['stderr']);
        }

        $id = trim($run['stdout']);
        $inspect = $this->command(['docker', 'inspect', '--format={{.Name}}', $id]);
        if ($inspect['exit_code'] !== 0 || trim($inspect['stdout']) !== '/'.$name) {
            throw new RuntimeException('assistant_read_limiter_redis_container_identity_invalid');
        }

        $portResult = $this->command(['docker', 'port', $id, '6379/tcp']);
        if ($portResult['exit_code'] !== 0 || preg_match('/127\.0\.0\.1:(\d+)/', $portResult['stdout'], $matches) !== 1) {
            $this->stopRedisContainer($id, $name);
            throw new RuntimeException('assistant_read_limiter_redis_container_port_unavailable');
        }

        return ['id' => $id, 'name' => $name, 'port' => (int) $matches[1]];
    }

    private function configureRedis(int $port): void
    {
        config([
            'database.redis.client' => 'predis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => $port,
            'database.redis.default.url' => null,
            'database.redis.default.password' => null,
            'database.redis.default.username' => null,
            'database.redis.default.database' => 0,
            'database.redis.default.options.parameters.timeout' => 2,
            'database.redis.default.options.parameters.read_write_timeout' => 2,
            'queue.connections.redis_ai_rag.connection' => 'default',
        ]);
        Redis::purge('default');
    }

    private function waitUntilRedisReady(object $redis): void
    {
        $deadline = microtime(true) + 10;
        do {
            try {
                if (strtoupper((string) $redis->ping()) === 'PONG') {
                    return;
                }
            } catch (Throwable) {
                usleep(100_000);
            }
        } while (microtime(true) < $deadline);

        self::fail('Isolated Redis test container did not become ready.');
    }

    private function runBurst(int $port, string $keyPrefix, int $workerCount): void
    {
        $redis = Redis::connection('default');
        $barrierKey = $keyPrefix.'barrier-ready';
        $startKey = $keyPrefix.'barrier-start';
        $redis->set($barrierKey, '0');
        $redis->set($startKey, '0');
        $workers = [];

        try {
            for ($index = 0; $index < $workerCount; ++$index) {
                $organizationId = 'org-'.($index % 4);
                $workers[] = $this->startWorker($port, $keyPrefix, $organizationId, 160, 400, $barrierKey, $workerCount, $startKey);
            }

            $deadline = microtime(true) + 60;
            while ((int) $redis->get($barrierKey) < $workerCount && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $readyCount = (int) $redis->get($barrierKey);
            if ($readyCount !== $workerCount) {
                $diagnostics = $this->workerDiagnostics($workers);
                throw new RuntimeException("assistant_read_workers_barrier_failed:{$readyCount}/{$workerCount}:{$diagnostics}");
            }
            $redis->set($startKey, '1');
            $burstStartedAt = hrtime(true);

            $results = $this->collectWorkers($workers, 35);
            self::assertCount($workerCount, $results);
            foreach ($results as $result) {
                self::assertSame('ok', $result['result'] ?? null);
            }

            $metricsPrefix = $keyPrefix.'metrics:';
            self::assertLessThanOrEqual(2, (int) $redis->get($metricsPrefix.'read-max'));
            self::assertSame(min($workerCount, 2), (int) $redis->get($metricsPrefix.'read-max'));
            self::assertSame(0, (int) $redis->get($metricsPrefix.'read-limit-breaches'));
            if ($workerCount > 1) {
                self::assertGreaterThan(0, (int) $redis->get($metricsPrefix.'read-provider-overlap'), 'A DB read must overlap another request’s provider wait after permit release.');
            }
            foreach (range(0, min($workerCount - 1, 3)) as $organizationIndex) {
                self::assertLessThanOrEqual(1, (int) $redis->get($metricsPrefix.'org-max:org-'.$organizationIndex));
            }
            $admissionWaits = array_column($results, 'admission_wait_ms');
            $totalElapsed = array_column($results, 'total_elapsed_ms');
            sort($admissionWaits);
            sort($totalElapsed);
            fwrite(STDOUT, PHP_EOL.'assistant_read_limiter_burst '.json_encode([
                'requests' => $workerCount,
                'admission_wait_ms_median' => $admissionWaits[(int) floor((count($admissionWaits) - 1) / 2)],
                'admission_wait_ms_p95' => $admissionWaits[max(0, (int) ceil(count($admissionWaits) * .95) - 1)],
                'admission_wait_ms_max' => max($admissionWaits),
                'request_elapsed_ms_p95' => $totalElapsed[max(0, (int) ceil(count($totalElapsed) * .95) - 1)],
                'burst_elapsed_ms' => round((hrtime(true) - $burstStartedAt) / 1_000_000, 2),
                'peak_read_db_permits' => (int) $redis->get($metricsPrefix.'read-max'),
                'peak_php_memory_bytes' => max(array_column($results, 'peak_memory_bytes')),
                'read_during_stub_provider_wait' => (int) $redis->get($metricsPrefix.'read-provider-overlap'),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            $redis->del($barrierKey, $startKey);
            $this->stopWorkers($workers);
        }
    }

    private function assertFailureAndCancellationReleasePermits(int $port, string $keyPrefix): void
    {
        $redis = Redis::connection('default');
        $limiter = new AssistantReadConcurrencyLimiter($keyPrefix.'permits:');
        $containerName = $keyPrefix.'semantics';
        $redis->set($containerName, 'ready');

        try {
            try {
                $limiter->run(static function (): never {
                    throw new RuntimeException('read_failure_fixture');
                }, static function (): void {
                }, 'failure-org');
                self::fail('Read exceptions must propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame('read_failure_fixture', $exception->getMessage());
            }

            self::assertSame('released', $limiter->run(static fn (): string => 'released', static function (): void {
            }, 'recovery-org'));

            $barrierKey = $keyPrefix.'hold-ready';
            $startKey = $keyPrefix.'hold-start';
            $redis->set($barrierKey, '0');
            $redis->set($startKey, '0');
            $worker = $this->startWorker($port, $keyPrefix.'holder:', 'cancel-org', 900, 0, $barrierKey, 1, $startKey);
            $deadline = microtime(true) + 10;
            while ((int) $redis->get($barrierKey) < 1 && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertSame(1, (int) $redis->get($barrierKey));
            $redis->set($startKey, '1');
            $permitDeadline = microtime(true) + 5;
            while ((int) $redis->zcard($keyPrefix.'holder:permits:{assistant-read-concurrency}:active') < 1 && microtime(true) < $permitDeadline) {
                usleep(10_000);
            }
            self::assertGreaterThanOrEqual(1, (int) $redis->zcard($keyPrefix.'holder:permits:{assistant-read-concurrency}:active'));

            $holderLimiter = new AssistantReadConcurrencyLimiter($keyPrefix.'holder:permits:');
            $checkpointStarted = microtime(true);
            try {
                $holderLimiter->run(static fn (): never => throw new RuntimeException('read_should_not_start'), static function () use ($checkpointStarted): void {
                    if (microtime(true) - $checkpointStarted >= 0.2) {
                        throw new RuntimeException('checkpoint_cancelled');
                    }
                }, 'cancel-org');
                self::fail('A cancelled admission must propagate the checkpoint exception.');
            } catch (RuntimeException $exception) {
                self::assertSame('checkpoint_cancelled', $exception->getMessage());
            }

            self::assertSame(0, (int) $redis->zcard($keyPrefix.'holder:permits:{assistant-read-concurrency}:waiters'));
            $results = $this->collectWorkers([$worker], 10);
            self::assertSame('ok', $results[0]['result'] ?? null);
            $worker = null;
        } finally {
            $this->stopWorkers(isset($worker) && is_array($worker) ? [$worker] : []);
            $redis->del($barrierKey ?? '', $startKey ?? '', $containerName);
        }
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startWorker(int $port, string $keyPrefix, string $organizationId, int $readHoldMs, int $providerHoldMs, string $barrierKey, int $workerCount, string $startKey): array
    {
        $payloadPath = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'worker-'.(++$this->workerSequence).'.json';
        $payload = [
            'redis_host' => '127.0.0.1',
            'redis_port' => $port,
            'key_prefix' => $keyPrefix,
            'organization_id' => $organizationId,
            'read_hold_ms' => $readHoldMs,
            'provider_hold_ms' => $providerHoldMs,
            'barrier_key' => $barrierKey,
            'worker_count' => $workerCount,
            'start_key' => $startKey,
            'connection' => $this->isolatedConnection,
            'app_key' => $this->appKey,
        ];
        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                base_path('tests/Support/assistant-read-concurrency-worker.php'),
                $payloadPath,
            ],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $this->workerEnvironment(),
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('assistant_read_limiter_worker_start_failed');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers @return list<array<string, mixed>> */
    private function collectWorkers(array $workers, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $results = [];
        $buffers = array_fill(0, count($workers), '');
        $finished = array_fill(0, count($workers), false);

        do {
            $allFinished = true;
            foreach ($workers as $index => $worker) {
                if ($finished[$index]) {
                    continue;
                }
                $buffers[$index] .= stream_get_contents($worker['pipes'][1]);
                $status = proc_get_status($worker['process']);
                if (! $status['running']) {
                    $finished[$index] = true;
                    if (preg_match('/\{[^\r\n]*\}/', $buffers[$index], $matches) !== 1) {
                        throw new RuntimeException('assistant_read_limiter_worker_failed:'.$status['exitcode']);
                    }
                    $results[] = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $allFinished = false;
                }
            }
            if ($allFinished) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        if (! $allFinished) {
            throw new RuntimeException('assistant_read_limiter_workers_timeout');
        }

        $this->stopWorkers($workers);

        return $results;
    }

    /** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($worker['process'])) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }
    }

    /** @param array<int, array{process: resource, pipes: array<int, resource>}> $workers */
    private function workerDiagnostics(array $workers): string
    {
        $diagnostics = [];
        foreach ($workers as $index => $worker) {
            $status = proc_get_status($worker['process']);
            $stdout = stream_get_contents($worker['pipes'][1]);
            $result = json_decode((string) $stdout, true);
            if (is_array($result)) {
                $diagnostics[] = $index.':'.json_encode(array_intersect_key($result, array_flip(['result', 'exception_class', 'file', 'line'])), JSON_THROW_ON_ERROR);
            } else {
                $diagnostics[] = $index.':'.($status['running'] ? 'running' : 'exit='.$status['exitcode']);
            }
        }

        return implode('|', $diagnostics);
    }

    private function stopRedisContainer(string $id, string $name): void
    {
        if (preg_match('/^[a-f0-9]{12,64}$/i', $id) !== 1 || preg_match('/^most-ai-assistant-redis-test-[a-f0-9]{16}$/', $name) !== 1) {
            throw new RuntimeException('assistant_read_limiter_redis_cleanup_target_invalid');
        }
        $inspect = $this->command(['docker', 'inspect', '--format={{.Name}}', $id]);
        if ($inspect['exit_code'] === 0 && trim($inspect['stdout']) === '/'.$name) {
            $this->command(['docker', 'rm', '--force', $id]);
        }
    }

    /** @param list<string> $arguments @return array{exit_code: int, stdout: string, stderr: string} */
    private function command(array $arguments): array
    {
        $pipes = [];
        $process = proc_open($arguments, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('assistant_read_limiter_command_start_failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : ''];
    }

    /** @param array<string, string> $values */
    private function setTestEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->originalEnvironment)) {
                $this->originalEnvironment[$key] = [
                    'environment' => getenv($key),
                    'env' => $_ENV[$key] ?? false,
                    'server' => $_SERVER[$key] ?? false,
                ];
            }
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** @return array<string, string> */
    private function workerEnvironment(): array
    {
        $connection = $this->isolatedConnection;
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => $this->appKey,
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'no-config-cache',
            'MOST_POSTGRES_TEST_PROFILE' => 'ai-assistant',
            'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '1',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_SCHEMA' => (string) $connection['search_path'],
            'DB_URL' => '',
            'REDIS_CLIENT' => 'predis',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $this->redisPort,
            'REDIS_DB' => '0',
            'REDIS_URL' => '',
        ];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH'] as $key) {
            if (is_string(getenv($key))) {
                $environment[$key] = (string) getenv($key);
            }
        }

        return $environment;
    }
}
