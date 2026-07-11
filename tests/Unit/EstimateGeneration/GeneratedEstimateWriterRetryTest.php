<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\Models\Estimate;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeneratedEstimateWriterRetryTest extends TestCase
{
    #[Test]
    public function target_number_collision_rolls_back_savepoint_and_uses_next_candidate(): void
    {
        $writer = $this->writer([
            $this->queryException('23505', 'estimates_organization_id_number_unique'),
            $this->estimate(91),
        ]);

        $estimate = $writer->createEstimateForTest($this->session());

        self::assertSame(91, $estimate->id);
        self::assertSame(['AI-42-0', 'AI-42-1'], $writer->attemptedNumbers);
        self::assertSame(2, $writer->savepoints);
    }

    #[Test]
    public function unrelated_query_exception_is_not_retried(): void
    {
        $exception = $this->queryException('23505', 'other_unique_constraint');
        $writer = $this->writer([$exception, $this->estimate(91)]);

        try {
            $writer->createEstimateForTest($this->session());
            self::fail('Expected unrelated query exception.');
        } catch (QueryException $actual) {
            self::assertSame($exception, $actual);
            self::assertSame(['AI-42-0'], $writer->attemptedNumbers);
            self::assertSame(1, $writer->savepoints);
        }
    }

    #[Test]
    public function non_postgresql_integrity_state_is_not_treated_as_retryable_collision(): void
    {
        $exception = $this->queryException('23000', 'estimates_organization_id_number_unique');
        $writer = $this->writer([$exception, $this->estimate(91)]);

        $this->expectExceptionObject($exception);

        try {
            $writer->createEstimateForTest($this->session());
        } finally {
            self::assertSame(['AI-42-0'], $writer->attemptedNumbers);
            self::assertSame(1, $writer->savepoints);
        }
    }

    #[Test]
    public function constraint_name_superstring_is_not_retryable(): void
    {
        $exception = $this->queryException('23505', 'archived_estimates_organization_id_number_unique_backup');
        $writer = $this->writer([$exception, $this->estimate(91)]);

        $this->expectExceptionObject($exception);

        try {
            $writer->createEstimateForTest($this->session());
        } finally {
            self::assertSame(['AI-42-0'], $writer->attemptedNumbers);
        }
    }

    #[Test]
    public function unparseable_unique_violation_is_not_retryable(): void
    {
        $exception = $this->queryException('23505', null);
        $writer = $this->writer([$exception, $this->estimate(91)]);

        $this->expectExceptionObject($exception);

        try {
            $writer->createEstimateForTest($this->session());
        } finally {
            self::assertSame(['AI-42-0'], $writer->attemptedNumbers);
        }
    }

    #[Test]
    public function retry_budget_exhaustion_rethrows_last_number_collision(): void
    {
        $collisions = array_map(
            fn (): QueryException => $this->queryException('23505', 'estimates_organization_id_number_unique'),
            range(1, 4),
        );
        $writer = $this->writer($collisions);

        $this->expectException(QueryException::class);

        try {
            $writer->createEstimateForTest($this->session());
        } finally {
            self::assertSame(['AI-42-0', 'AI-42-1', 'AI-42-2', 'AI-42-3'], $writer->attemptedNumbers);
            self::assertSame(4, $writer->savepoints);
        }
    }

    /** @param array<int, Estimate|\Throwable> $outcomes */
    private function writer(array $outcomes): TestRetryGeneratedEstimateWriter
    {
        return new TestRetryGeneratedEstimateWriter(
            new EstimateDraftPersistenceService(
                new EstimateGenerationFinalWorkItemGuard,
                new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter),
            ),
            new SequentialGeneratedEstimateNumberAllocator,
            $outcomes,
        );
    }

    private function session(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'input_payload' => [],
        ]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }

    private function estimate(int $id): Estimate
    {
        $estimate = new Estimate;
        $estimate->id = $id;
        $estimate->exists = true;

        return $estimate;
    }

    private function queryException(string $sqlState, ?string $constraint): QueryException
    {
        $message = $constraint === null
            ? 'duplicate key violates a unique index'
            : sprintf('duplicate key violates constraint "%s"', $constraint);
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, 7, $message];

        return new QueryException('pgsql', 'insert into estimates', [], $previous);
    }
}

final class SequentialGeneratedEstimateNumberAllocator implements GeneratedEstimateNumberAllocator
{
    public function allocate(EstimateGenerationSession $session, int $attempt): string
    {
        return sprintf('AI-%d-%d', (int) $session->getKey(), $attempt);
    }
}

final class TestRetryGeneratedEstimateWriter extends LaravelGeneratedEstimateWriter
{
    public int $savepoints = 0;

    /** @var array<int, string> */
    public array $attemptedNumbers = [];

    /** @param array<int, Estimate|\Throwable> $outcomes */
    public function __construct(
        EstimateDraftPersistenceService $draftService,
        GeneratedEstimateNumberAllocator $numberAllocator,
        private array $outcomes,
    ) {
        parent::__construct($draftService, $numberAllocator);
    }

    public function createEstimateForTest(EstimateGenerationSession $session): Estimate
    {
        return $this->createEstimate(
            $session,
            new ApplyGeneratedEstimateCommand(42, 10, 20, 0),
            [],
            [],
            100,
        );
    }

    protected function transactionAttempt(callable $callback): mixed
    {
        $this->savepoints++;

        return $callback();
    }

    protected function createEstimateAttempt(array $attributes): Estimate
    {
        $this->attemptedNumbers[] = (string) $attributes['number'];
        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        if (! $outcome instanceof Estimate) {
            throw new \LogicException('Missing test outcome.');
        }

        return $outcome;
    }
}
