<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class RegistrationIdempotencyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_same_key_executes_registration_once_and_replays_one_result(): void
    {
        DB::commit();
        $key = 'concurrent-registration-key';
        $payload = [
            'name' => 'Concurrent Owner',
            'email' => 'concurrent-registration@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Concurrent Registration Organization',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
        $processes = [];
        $advisoryLocked = false;

        try {
            DB::select('SELECT pg_advisory_lock(hashtext(?), hashtext(?))', ['lk', $key]);
            $advisoryLocked = true;
            $processes[] = $this->startWorker($key, $payload);
            $processes[] = $this->startWorker($key, $payload);
            $backendPids = array_map(static fn (array $worker): int => $worker['backend_pid'], $processes);
            $this->waitUntilBothWorkersAreLocked($backendPids);

            DB::select('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', ['lk', $key]);
            $advisoryLocked = false;
            $results = array_map(fn (array $worker): array => $this->finishWorker($worker), $processes);
            $processes = [];

            self::assertTrue($results[0]['success']);
            self::assertTrue($results[1]['success']);
            self::assertSame($results[0]['user_id'], $results[1]['user_id']);
            self::assertSame($results[0]['organization_id'], $results[1]['organization_id']);
            self::assertNotSame($results[0]['replay'], $results[1]['replay']);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('organization_user', 1);
            $this->assertDatabaseCount('auth_registration_attempts', 1);
        } finally {
            if ($advisoryLocked) {
                DB::select('SELECT pg_advisory_unlock(hashtext(?), hashtext(?))', ['lk', $key]);
            }
            foreach ($processes as $worker) {
                $this->terminateWorker($worker);
            }
        }
    }

    private function startWorker(string $key, array $payload): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $connection = (array) config('database.connections.'.config('database.default'));
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'MOST_REGISTRATION_PAYLOAD' => json_encode([
                'key' => $key,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR),
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/Auth/registration_idempotency_worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start registration worker.');
        }

        fclose($pipes[0]);
        $ready = fgets($pipes[1]);

        if (!is_string($ready) || preg_match('/^READY:([1-9][0-9]*)\R$/D', $ready, $matches) !== 1) {
            throw new RuntimeException('Registration worker did not become ready: '.stream_get_contents($pipes[2]));
        }

        return ['process' => $process, 'pipes' => $pipes, 'backend_pid' => (int) $matches[1]];
    }

    private function finishWorker(array $worker): array
    {
        $line = fgets($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0 || !is_string($line)) {
            throw new RuntimeException('Registration worker failed: '.$stderr);
        }

        return (array) json_decode(trim($line), true, 16, JSON_THROW_ON_ERROR);
    }

    private function waitUntilBothWorkersAreLocked(array $backendPids): void
    {
        $deadline = microtime(true) + 10;

        do {
            $locked = DB::table('pg_stat_activity')
                ->whereIn('pid', $backendPids)
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($locked === 2) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Concurrent registration workers did not reach the lock barrier.');
    }

    private function terminateWorker(array $worker): void
    {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
    }
}
