<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use Fiber;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;
use WeakMap;

final class OrdinaryAssistantLowerEnvironmentProfiler
{
    private const CATEGORIES = [
        'freshness_scope', 'reviewer_user_membership', 'approved_sql_source_capture',
        'approved_document_source_capture', 'document_corpus', 'retrieval', 'projection/reference_guard',
    ];

    private const MAX_COUNT = 1_000_000;

    private const MAX_DURATION_MS = 86_400_000;

    private static ?WeakMap $owners = null;

    private array $categories = [];

    private array $stack = [];

    private ?Fiber $ownerFiber = null;

    public function __construct(private readonly bool $enabled, private readonly string $environment) {}

    public function run(string $category, callable $operation): mixed
    {
        if (! $this->enabled || ! in_array($this->environment, ['local', 'testing', 'staging'], true)
            || extension_loaded('swoole') || extension_loaded('openswoole')
            || ! in_array($category, self::CATEGORIES, true) || count($this->stack) >= 32
            || ($this->stack !== [] && Fiber::getCurrent() !== $this->ownerFiber)) {
            return $operation();
        }

        $connection = null;
        $previous = null;
        $dispatcher = null;
        $attached = $this->stack !== [];
        if ($this->stack === []) {
            try {
                $connection = app('db')->connection('pgsql');
                self::$owners ??= new WeakMap;
                $previous = $connection->getEventDispatcher();
                if (! isset(self::$owners[$connection]) && $previous !== null) {
                    $dispatcher = clone $previous;
                    $fiber = Fiber::getCurrent();
                    $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($fiber): void {
                        if (Fiber::getCurrent() === $fiber && $this->stack !== []) {
                            $this->recordQuery((float) $event->time);
                        }
                    });
                    $connection->setEventDispatcher($dispatcher);
                    self::$owners[$connection] = $this;
                    $this->ownerFiber = $fiber;
                    $attached = true;
                }
            } catch (Throwable) {
            }
        }
        if (! $attached) {
            return $operation();
        }

        $this->categories[$category] ??= ['count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0,
            'query_count' => 0, 'query_total_ms' => 0.0, 'query_max_ms' => 0.0];
        $this->stack[] = $category;
        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            array_pop($this->stack);
            $duration = min(self::MAX_DURATION_MS, (hrtime(true) - $started) / 1_000_000);
            $record = &$this->categories[$category];
            $record['count'] = min(self::MAX_COUNT, $record['count'] + 1);
            $record['total_ms'] = min(self::MAX_DURATION_MS, $record['total_ms'] + $duration);
            $record['max_ms'] = max($record['max_ms'], $duration);
            if ($connection instanceof Connection && $dispatcher !== null) {
                try {
                    if ($connection->getEventDispatcher() === $dispatcher) {
                        $connection->setEventDispatcher($previous);
                    }
                } catch (Throwable) {
                } finally {
                    unset(self::$owners[$connection]);
                    $this->ownerFiber = null;
                }
            }
        }
    }

    public function snapshot(): array
    {
        return $this->categories;
    }

    private function recordQuery(float $duration): void
    {
        if (! is_finite($duration) || $duration < 0) {
            return;
        }
        $duration = min(self::MAX_DURATION_MS, $duration);
        $category = $this->stack[array_key_last($this->stack)];
        $record = &$this->categories[$category];
        $record['query_count'] = min(self::MAX_COUNT, $record['query_count'] + 1);
        $record['query_total_ms'] = min(self::MAX_DURATION_MS, $record['query_total_ms'] + $duration);
        $record['query_max_ms'] = max($record['query_max_ms'], $duration);
    }
}
