<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use PDO;
use ReflectionMethod;
use Throwable;
use WeakMap;

final class SqlConnectionState
{
    private WeakMap $connections;

    public function __construct()
    {
        $this->connections = new WeakMap;
    }

    public function watch(Connection $connection): void
    {
        try {
            if (isset($this->connections[$connection])) {
                return;
            }
            $this->connections[$connection] = (object) ['pending' => null, 'poisoned' => false];
            foreach (['getRawPdo', 'getRawReadPdo', 'beforeExecuting'] as $method) {
                if ((new ReflectionMethod($connection, $method))->getDeclaringClass()->getName() !== Connection::class) {
                    $this->poison($connection);

                    return;
                }
            }
            $connection->beforeExecuting(function (string $sql, array $bindings, Connection $current): void {
                $this->before($current, $sql);
            });
        } catch (Throwable) {
            $this->poison($connection);
        }
    }

    public function established(Connection $connection): void
    {
        try {
            $this->watch($connection);
            $state = $this->connections[$connection];
            if (! $state->poisoned && $state->pending !== null) {
                $state->pending['manager_connection_event_during_run'] = true;
            }
        } catch (Throwable) {
            $this->poison($connection);
        }
    }

    public function finish(QueryExecuted $query): array
    {
        try {
            $state = $this->connections[$query->connection] ?? null;
            if ($state === null || $state->poisoned || $state->pending === null) {
                return ['observed' => false];
            }
            $pending = $state->pending;
            $state->pending = null;
            if (! hash_equals($pending['statement'], hash('sha256', $query->sql, true))) {
                $this->poison($query->connection);

                return ['observed' => false];
            }
            $write = $query->connection->getRawPdo();
            $read = $query->connection->getRawReadPdo();
            if (! $this->supportedHandle($write) || ! $this->supportedHandle($read)) {
                $this->poison($query->connection);

                return ['observed' => false];
            }

            return [
                'observed' => true,
                'write_lazy_before' => $pending['write_lazy_before'],
                'read_lazy_before' => $pending['read_lazy_before'],
                'write_ready_before' => $pending['write_ready_before'],
                'read_ready_before' => $pending['read_ready_before'],
                'write_ready_after' => $write instanceof PDO,
                'read_ready_after' => $read instanceof PDO,
                'manager_connection_event_during_run' => $pending['manager_connection_event_during_run'],
            ];
        } catch (Throwable) {
            $this->poison($query->connection);

            return ['observed' => false];
        }
    }

    private function before(Connection $connection, string $sql): void
    {
        try {
            $state = $this->connections[$connection] ?? null;
            if ($state === null || $state->poisoned) {
                return;
            }
            if ($state->pending !== null || strlen($sql) > 2 * 1024 * 1024) {
                $this->poison($connection);

                return;
            }
            $write = $connection->getRawPdo();
            $read = $connection->getRawReadPdo();
            if (! $this->supportedHandle($write) || ! $this->supportedHandle($read)) {
                $this->poison($connection);

                return;
            }
            $state->pending = [
                'statement' => hash('sha256', $sql, true),
                'write_lazy_before' => $write instanceof Closure,
                'read_lazy_before' => $read instanceof Closure,
                'write_ready_before' => $write instanceof PDO,
                'read_ready_before' => $read instanceof PDO,
                'manager_connection_event_during_run' => false,
            ];
        } catch (Throwable) {
            $this->poison($connection);
        }
    }

    private function supportedHandle(mixed $handle): bool
    {
        return $handle === null || $handle instanceof PDO || $handle instanceof Closure;
    }

    private function poison(Connection $connection): void
    {
        $this->connections[$connection] = (object) ['pending' => null, 'poisoned' => true];
    }
}
