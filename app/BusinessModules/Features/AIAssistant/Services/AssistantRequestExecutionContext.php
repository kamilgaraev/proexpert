<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use WeakMap;

final class AssistantRequestExecutionContext
{
    private const SQL_STATEMENT_TIMEOUT_MILLISECONDS = 5000;

    private static ?WeakMap $guardedConnections = null;

    private int $deadlineNanoseconds;

    private array $originalStatementTimeouts = [];

    private bool $databaseGuardsActive = false;

    private array $operationDeadlines = [];

    private ?int $cleanupDeadlineNanoseconds = null;

    private bool $lifecycleCheckpointInProgress = false;

    public function __construct(
        private readonly AssistantRequestLifecycle $lifecycle,
        private readonly AssistantRequest $request,
        private readonly User $actor,
        int $budgetMilliseconds,
    ) {
        $createdAtMilliseconds = $request->created_at?->valueOf();
        $elapsedMilliseconds = $createdAtMilliseconds === null ? 0 : (int) max(0, now()->valueOf() - $createdAtMilliseconds);
        $remainingMilliseconds = max(1, $budgetMilliseconds - $elapsedMilliseconds);
        $this->deadlineNanoseconds = hrtime(true) + ($remainingMilliseconds * 1_000_000);
    }

    public function activate(): void
    {
        $this->registerConnection(DB::connection());
        $this->databaseGuardsActive = true;
    }

    public function assertCanContinue(): void
    {
        if ($this->cleanupDeadlineNanoseconds !== null) {
            $this->assertCleanupBudget();

            return;
        }
        if ($this->lifecycleCheckpointInProgress) {
            $this->remainingMilliseconds();

            return;
        }

        $this->lifecycleCheckpointInProgress = true;
        try {
            $this->lifecycle->checkpoint($this->request, $this->actor);
            $this->assertWithinDeadline();
        } finally {
            $this->lifecycleCheckpointInProgress = false;
        }
    }

    public function assertWithinDeadline(): void
    {
        if ($this->cleanupDeadlineNanoseconds !== null) {
            $this->assertCleanupBudget();

            return;
        }
        if ($this->deadlineNanoseconds <= hrtime(true)) {
            throw new AssistantRequestDeadlineExceeded;
        }
    }

    public function deadlineExceeded(): bool
    {
        return $this->deadlineNanoseconds <= hrtime(true);
    }

    public function remainingMilliseconds(): int
    {
        if ($this->cleanupDeadlineNanoseconds !== null) {
            $remainingCleanupMilliseconds = intdiv($this->cleanupDeadlineNanoseconds - hrtime(true), 1_000_000);
            if ($remainingCleanupMilliseconds <= 0) {
                throw new AssistantRequestDeadlineExceeded;
            }

            return $remainingCleanupMilliseconds;
        }
        $deadline = $this->effectiveDeadlineNanoseconds();
        $remaining = intdiv($deadline - hrtime(true), 1_000_000);
        if ($remaining <= 0) {
            throw new AssistantRequestDeadlineExceeded;
        }

        return $remaining;
    }

    public function remainingSeconds(int|float $ceilingSeconds): float
    {
        $this->assertCanContinue();

        return min((float) $ceilingSeconds, $this->remainingMilliseconds() / 1000);
    }

    public function withDatabaseStatementTimeout(callable $operation, ?string $connection = null): mixed
    {
        $this->assertCanContinue();
        $this->registerConnection(DB::connection($connection));
        $this->databaseGuardsActive = true;

        return $operation();
    }

    public function withOperationBudget(callable $operation, int $budgetMilliseconds, ?string $connection = null): mixed
    {
        $this->assertCanContinue();
        $deadline = min($this->effectiveDeadlineNanoseconds(), hrtime(true) + (max(1, $budgetMilliseconds) * 1_000_000));
        $this->operationDeadlines[] = $deadline;

        try {
            $result = $this->withDatabaseStatementTimeout($operation, $connection);
            $this->assertCanContinue();
            if ($deadline <= hrtime(true)) {
                throw new AssistantRequestDeadlineExceeded;
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($deadline <= hrtime(true)) {
                throw new AssistantRequestDeadlineExceeded(previous: $exception);
            }

            throw $exception;
        } finally {
            array_pop($this->operationDeadlines);
        }
    }

    public function withCleanupBudget(callable $operation, int $budgetMilliseconds = 5000, ?string $connection = null): mixed
    {
        $previousCleanupDeadline = $this->cleanupDeadlineNanoseconds;
        $cleanupDeadline = hrtime(true) + (max(1, $budgetMilliseconds) * 1_000_000);
        $this->cleanupDeadlineNanoseconds = $previousCleanupDeadline === null
            ? $cleanupDeadline
            : min($previousCleanupDeadline, $cleanupDeadline);
        $wasActive = $this->databaseGuardsActive;
        $this->databaseGuardsActive = true;

        try {
            $this->registerConnection(DB::connection($connection));
            $result = $operation();
            $this->assertCleanupBudget();

            return $result;
        } finally {
            $this->cleanupDeadlineNanoseconds = $previousCleanupDeadline;
            $this->databaseGuardsActive = $wasActive;
        }
    }

    public function restoreDatabaseStatementTimeouts(): void
    {
        $this->databaseGuardsActive = false;
        foreach ($this->originalStatementTimeouts as $state) {
            try {
                $statement = $state['connection']->getPdo()->prepare("SELECT set_config('statement_timeout', ?, false)");
                $statement->execute([$state['statement_timeout']]);
            } catch (\Throwable) {
            }
        }

        $this->originalStatementTimeouts = [];
        $this->databaseGuardsActive = false;
    }

    private function registerConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connectionId = spl_object_id($connection);
        if (! isset($this->originalStatementTimeouts[$connectionId])) {
            $statement = $connection->getPdo()->query('SHOW statement_timeout');
            $originalTimeout = $statement === false ? '0' : (string) $statement->fetchColumn();
            $this->originalStatementTimeouts[$connectionId] = [
                'connection' => $connection,
                'statement_timeout' => $originalTimeout,
            ];
        }

        self::$guardedConnections ??= new WeakMap;
        if (isset(self::$guardedConnections[$connection])) {
            return;
        }

        $connection->beforeExecuting(static function (string $query, array $bindings, Connection $connection): void {
            if (! app()->bound(self::class)) {
                return;
            }

            app(self::class)->applyStatementTimeout($connection);
        });
        self::$guardedConnections[$connection] = true;
    }

    private function applyStatementTimeout(Connection $connection): void
    {
        if (! $this->databaseGuardsActive || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        $milliseconds = min(self::SQL_STATEMENT_TIMEOUT_MILLISECONDS, $this->remainingMilliseconds());
        $statement = $connection->getPdo()->prepare("SELECT set_config('statement_timeout', ?, false)");
        $statement->execute([$milliseconds.'ms']);
    }

    private function effectiveDeadlineNanoseconds(): int
    {
        $deadline = $this->deadlineNanoseconds;
        foreach ($this->operationDeadlines as $operationDeadline) {
            $deadline = min($deadline, $operationDeadline);
        }

        return $deadline;
    }

    private function assertCleanupBudget(): void
    {
        if ($this->cleanupDeadlineNanoseconds === null || $this->cleanupDeadlineNanoseconds <= hrtime(true)) {
            throw new AssistantRequestDeadlineExceeded;
        }
    }
}
