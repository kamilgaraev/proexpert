<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PostgresProcessRaceHarness
{
    public function __construct(private readonly string $directory)
    {
        if (
            ! function_exists('pcntl_fork')
            || ! function_exists('pcntl_waitpid')
            || ! function_exists('posix_kill')
        ) {
            throw new RuntimeException('PostgreSQL process race tests require pcntl and posix.');
        }
        if (! mkdir($this->directory)) {
            throw new RuntimeException('PostgreSQL process race directory could not be created.');
        }
    }

    public function independentConnection(string $name): ConnectionInterface
    {
        $default = (string) config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$default}")]);

        return DB::connection($name);
    }

    public function spawn(int $index, callable $worker): int
    {
        $pid = (int) call_user_func('pcntl_fork');
        if ($pid < 0) {
            throw new RuntimeException('PostgreSQL process race fork failed.');
        }
        if ($pid !== 0) {
            return $pid;
        }

        try {
            $this->waitForFile($this->path("go-{$index}"), 10.0);
            DB::purge();
            DB::statement("SET lock_timeout = '30s'");
            DB::statement("SET statement_timeout = '30s'");
            $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
            file_put_contents($this->path("pid-{$index}"), (string) $backend->pid);
            $result = ['ok' => true, 'value' => $worker()];
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
        }
        file_put_contents(
            $this->path("result-{$index}.json"),
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        exit($result['ok'] ? 0 : 1);
    }

    public function release(int $index): void
    {
        file_put_contents($this->path("go-{$index}"), 'go');
    }

    public function waitForWorkerBackendPid(int $index): int
    {
        $path = $this->path("pid-{$index}");
        $this->waitForFile($path, 10.0);

        return (int) file_get_contents($path);
    }

    public function waitForPostgresWait(
        ConnectionInterface $connection,
        int $backendPid,
        string $waitEvent = 'transactionid',
        float $timeoutSeconds = 10.0,
    ): void {
        $this->waitUntil(function () use ($connection, $backendPid, $waitEvent): bool {
            $activity = $connection->selectOne(
                'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?',
                [$backendPid],
            );

            return $activity !== null
                && $activity->wait_event_type === 'Lock'
                && $activity->wait_event === $waitEvent;
        }, $timeoutSeconds, "Backend {$backendPid} did not enter {$waitEvent} lock wait.");
    }

    public function waitForChildren(array $children, float $timeoutSeconds = 15.0): void
    {
        $remaining = array_fill_keys($children, true);
        $deadline = microtime(true) + $timeoutSeconds;
        while ($remaining !== []) {
            foreach (array_keys($remaining) as $pid) {
                $status = 0;
                $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
                if ($result === $pid) {
                    if ($status !== 0) {
                        throw new RuntimeException("Concurrent worker {$pid} failed.");
                    }
                    unset($remaining[$pid]);
                }
            }
            if ($remaining !== [] && microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for concurrent workers.');
            }
            if ($remaining !== []) {
                usleep(10000);
            }
        }
    }

    public function result(int $index): array
    {
        $path = $this->path("result-{$index}.json");
        $this->waitForFile($path, 2.0);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($decoded['message'] ?? 'worker_failed'));
        }

        return (array) $decoded['value'];
    }

    public function terminateAndReap(array $children): void
    {
        foreach ($children as $pid) {
            $status = 0;
            $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
            if ($result !== 0) {
                continue;
            }
            call_user_func('posix_kill', $pid, 15);
            $deadline = microtime(true) + 2.0;
            while ($result === 0 && microtime(true) < $deadline) {
                usleep(10000);
                $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
            }
            if ($result !== 0) {
                continue;
            }
            call_user_func('posix_kill', $pid, 9);
            $deadline = microtime(true) + 2.0;
            while ($result === 0 && microtime(true) < $deadline) {
                usleep(10000);
                $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
            }
        }
    }

    public function cleanup(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function cleanupStep(callable $cleanup, ?Throwable &$failure): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
    }

    private function waitForFile(string $path, float $timeoutSeconds): void
    {
        $this->waitUntil(static fn (): bool => is_file($path), $timeoutSeconds, "Timed out waiting for {$path}.");
    }

    private function waitUntil(callable $condition, float $timeoutSeconds, string $failure): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (! $condition()) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($failure);
            }
            usleep(10000);
        }
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }
}
