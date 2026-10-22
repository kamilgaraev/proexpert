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
use WeakReference;

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
            $this->connections[$connection] = (object) ['pending' => null, 'poisoned' => false, 'intervals_supported' => true];
            foreach (['getRawPdo', 'getRawReadPdo', 'beforeExecuting'] as $method) {
                if ((new ReflectionMethod($connection, $method))->getDeclaringClass()->getName() !== Connection::class) {
                    $this->poison($connection);

                    return;
                }
            }
            foreach (['run', 'runQueryCallback', 'prepared', 'logQuery', 'select', 'selectResultSets', 'cursor', 'getPdoForSelect'] as $method) {
                if ((new ReflectionMethod($connection, $method))->getDeclaringClass()->getName() !== Connection::class) {
                    $this->connections[$connection]->intervals_supported = false;
                    break;
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

    public function prepared(Connection $connection): void
    {
        try {
            $at = hrtime(true);
            $state = $this->connections[$connection] ?? null;
            if ($state === null || $state->poisoned || $state->pending === null || ! $state->intervals_supported) {
                return;
            }
            $state->pending['prepared_count']++;
            if ($state->pending['prepared_count'] !== 1) {
                $state->pending['intervals_valid'] = false;
                return;
            }
            $write = $connection->getRawPdo();
            $read = $connection->getRawReadPdo();
            foreach (['write' => $write, 'read' => $read] as $name => $handle) {
                $before = $state->pending[$name.'_before_reference'];
                if ($before !== null && ($before->get() === null || $before->get() !== $handle)) {
                    $state->pending['intervals_valid'] = false;
                }
                $state->pending[$name.'_prepared_reference'] = is_object($handle) ? WeakReference::create($handle) : null;
            }
            $state->pending['prepared_at'] = $at;
        } catch (Throwable) {
            if (isset($this->connections[$connection]) && $this->connections[$connection]->pending !== null) {
                $this->connections[$connection]->pending['intervals_valid'] = false;
            }
        }
    }

    public function finish(QueryExecuted $query): array
    {
        try {
            $at = hrtime(true);
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
                'event_intervals' => $this->intervals($pending, $write, $read, $at),
            ];
        } catch (Throwable) {
            $this->poison($query->connection);

            return ['observed' => false];
        }
    }

    private function before(Connection $connection, string $sql): void
    {
        try {
            $at = hrtime(true);
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
                'before_at' => $at,
                'prepared_at' => null,
                'prepared_count' => 0,
                'intervals_valid' => $state->intervals_supported,
                'write_before_reference' => $write instanceof PDO ? WeakReference::create($write) : null,
                'read_before_reference' => $read instanceof PDO ? WeakReference::create($read) : null,
                'write_prepared_reference' => null,
                'read_prepared_reference' => null,
            ];
        } catch (Throwable) {
            $this->poison($connection);
        }
    }

    private function intervals(array $pending, mixed $write, mixed $read, int $at): array
    {
        if (! $pending['intervals_valid'] || $pending['prepared_count'] !== 1
            || ! is_int($pending['prepared_at']) || $pending['prepared_at'] < $pending['before_at']
            || $at < $pending['prepared_at']) {
            return ['observed' => false];
        }
        foreach (['write' => $write, 'read' => $read] as $name => $handle) {
            $reference = $pending[$name.'_prepared_reference'];
            if ($reference === null ? $handle !== null : ($reference->get() === null || $reference->get() !== $handle)) {
                return ['observed' => false];
            }
        }

        return [
            'observed' => true,
            'before_to_prepared_ms' => round(($pending['prepared_at'] - $pending['before_at']) / 1_000_000, 3),
            'prepared_to_query_event_ms' => round(($at - $pending['prepared_at']) / 1_000_000, 3),
        ];
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
