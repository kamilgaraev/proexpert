<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\Contracts\OneCExchangeClientInterface;
use App\Services\OneCExchange\Contracts\OneCExchangeOperationRepositoryInterface;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryAttempt;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryOperation;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryPayload;
use App\Services\OneCExchange\DTOs\OneCExchangeDeliveryResult;
use App\Services\OneCExchange\OneCExchangeDeliveryOrchestrator;
use App\Services\OneCExchange\Support\OneCExchangePayloadSanitizer;
use App\Services\OneCExchange\Support\OneCExchangeRetryPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OneCExchangeDeliveryOrchestratorTest extends TestCase
{
    public function test_successful_delivery_marks_operation_delivered(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(status: 'queued'),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: true,
                status: 'delivered',
                retryable: false,
                failureType: null,
                safeErrorCode: null,
                safeErrorMessage: null,
                externalId: '1c-contract-10',
                transportStatus: 200,
                rawResponse: ['external_id' => '1c-contract-10', 'token' => 'secret-token'],
            ),
        ]);

        $summary = $this->orchestrator($repository, $client)->run(
            limit: 5,
            now: new DateTimeImmutable('2026-06-07 10:00:00')
        );

        self::assertSame(1, $summary->processed);
        self::assertSame(1, $summary->delivered);
        self::assertSame('delivered', $repository->statusFor(100));
        self::assertCount(1, $client->payloads);
        self::assertSame('org:contract:10:3', $client->payloads[0]->idempotencyKey);
        self::assertSame('1c-contract-10', $repository->attemptsFor(100)[0]->externalId);
    }

    public function test_transient_failure_schedules_retry_without_raw_diagnostics(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(status: 'queued'),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: false,
                status: 'failed',
                retryable: true,
                failureType: 'timeout',
                safeErrorCode: 'timeout',
                safeErrorMessage: 'SQLSTATE[08006]: stack trace token secret',
                externalId: null,
                transportStatus: null,
                rawResponse: [
                    'payload' => ['raw' => 'must not leak'],
                    'stack_trace' => 'Stack trace in /app/file.php',
                    'token' => 'secret-token',
                    'document' => ['number' => 'CNT-10'],
                ],
            ),
        ]);

        $summary = $this->orchestrator($repository, $client)->run(
            limit: 5,
            now: new DateTimeImmutable('2026-06-07 10:00:00')
        );

        $attempt = $repository->attemptsFor(100)[0];

        self::assertSame(1, $summary->retryScheduled);
        self::assertSame('retry_scheduled', $repository->statusFor(100));
        self::assertTrue($attempt->retryable);
        self::assertNotNull($attempt->nextRetryAt);
        self::assertSame('timeout', $attempt->safeErrorCode);
        self::assertArrayNotHasKey('payload', $attempt->safeResponsePreview);
        self::assertArrayNotHasKey('stack_trace', $attempt->safeResponsePreview);
        self::assertSame('[скрыто]', $attempt->safeResponsePreview['token']);
    }

    public function test_permanent_failure_moves_operation_to_dead_letter(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(status: 'queued'),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: false,
                status: 'rejected',
                retryable: false,
                failureType: 'business_validation',
                safeErrorCode: 'business_validation',
                safeErrorMessage: 'Документ отклонен учетной системой.',
                externalId: null,
                transportStatus: 422,
                rawResponse: ['message' => 'Документ отклонен учетной системой.'],
            ),
        ]);

        $summary = $this->orchestrator($repository, $client)->run(
            limit: 5,
            now: new DateTimeImmutable('2026-06-07 10:00:00')
        );

        self::assertSame(1, $summary->deadLettered);
        self::assertSame('dead_letter', $repository->statusFor(100));
        self::assertFalse($repository->attemptsFor(100)[0]->retryable);
    }

    public function test_max_attempts_exhausted_moves_operation_to_dead_letter(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(status: 'retry_scheduled', retryCount: 4, maxAttempts: 5),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: false,
                status: 'failed',
                retryable: true,
                failureType: 'server_error',
                safeErrorCode: 'server_error',
                safeErrorMessage: 'Учетная система временно недоступна.',
                externalId: null,
                transportStatus: 503,
                rawResponse: ['message' => 'temporary unavailable'],
            ),
        ]);

        $summary = $this->orchestrator($repository, $client)->run(
            limit: 5,
            now: new DateTimeImmutable('2026-06-07 10:00:00')
        );

        self::assertSame(1, $summary->deadLettered);
        self::assertSame('dead_letter', $repository->statusFor(100));
        self::assertNull($repository->attemptsFor(100)[0]->nextRetryAt);
    }

    public function test_repeated_run_does_not_deliver_claimed_operation_twice(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(status: 'queued'),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: true,
                status: 'delivered',
                retryable: false,
                failureType: null,
                safeErrorCode: null,
                safeErrorMessage: null,
                externalId: '1c-contract-10',
                transportStatus: 200,
                rawResponse: ['external_id' => '1c-contract-10'],
            ),
        ]);
        $orchestrator = $this->orchestrator($repository, $client);

        $orchestrator->run(limit: 5, now: new DateTimeImmutable('2026-06-07 10:00:00'));
        $secondSummary = $orchestrator->run(limit: 5, now: new DateTimeImmutable('2026-06-07 10:00:00'));

        self::assertSame(0, $secondSummary->processed);
        self::assertCount(1, $client->payloads);
        self::assertCount(1, $repository->attemptsFor(100));
    }

    public function test_stale_processing_operation_can_be_reclaimed_after_timeout(): void
    {
        $repository = new InMemoryOneCExchangeOperationRepository([
            $this->operation(
                status: 'processing',
                startedAt: new DateTimeImmutable('2026-06-07 09:40:00')
            ),
        ]);
        $client = new SequenceOneCExchangeClient([
            new OneCExchangeDeliveryResult(
                accepted: true,
                status: 'delivered',
                retryable: false,
                failureType: null,
                safeErrorCode: null,
                safeErrorMessage: null,
                externalId: '1c-contract-10',
                transportStatus: 200,
                rawResponse: ['external_id' => '1c-contract-10'],
            ),
        ]);

        $summary = $this->orchestrator($repository, $client)->run(
            limit: 5,
            now: new DateTimeImmutable('2026-06-07 10:00:00')
        );

        self::assertSame(1, $summary->processed);
        self::assertSame('delivered', $repository->statusFor(100));
    }

    private function orchestrator(
        InMemoryOneCExchangeOperationRepository $repository,
        SequenceOneCExchangeClient $client
    ): OneCExchangeDeliveryOrchestrator {
        return new OneCExchangeDeliveryOrchestrator(
            repository: $repository,
            client: $client,
            retryPolicy: new OneCExchangeRetryPolicy(),
            sanitizer: new OneCExchangePayloadSanitizer(),
        );
    }

    private function operation(
        string $status,
        int $retryCount = 0,
        int $maxAttempts = 5,
        ?DateTimeImmutable $startedAt = null
    ): OneCExchangeDeliveryOperation {
        return new OneCExchangeDeliveryOperation(
            id: 100,
            organizationId: 15,
            operationKey: 'operation-100',
            correlationId: 'corr-100',
            direction: 'export',
            scope: 'contracts',
            entityType: 'contract',
            entityId: '10',
            idempotencyKey: 'org:contract:10:3',
            status: $status,
            retryCount: $retryCount,
            maxAttempts: $maxAttempts,
            failureType: null,
            accountingStatus: null,
            safePayloadPreview: [
                'number' => 'CNT-10',
                'amount' => 250000,
            ],
            summary: [
                'source_is_actual' => true,
                'started_at' => $startedAt?->format(DateTimeImmutable::ATOM),
            ],
            nextRetryAt: null,
        );
    }
}

final class InMemoryOneCExchangeOperationRepository implements OneCExchangeOperationRepositoryInterface
{
    /** @var array<int, OneCExchangeDeliveryOperation> */
    private array $operations = [];

    /** @var array<int, list<OneCExchangeDeliveryAttempt>> */
    private array $attempts = [];

    /**
     * @param list<OneCExchangeDeliveryOperation> $operations
     */
    public function __construct(array $operations)
    {
        foreach ($operations as $operation) {
            $this->operations[$operation->id] = $operation;
        }
    }

    public function claimNextDue(DateTimeImmutable $now): ?OneCExchangeDeliveryOperation
    {
        foreach ($this->operations as $operation) {
            $startedAt = isset($operation->summary['started_at'])
                ? new DateTimeImmutable((string) $operation->summary['started_at'])
                : null;
            $isStaleProcessing = $operation->status === 'processing'
                && $startedAt !== null
                && $startedAt <= $now->modify('-15 minutes');

            if (!in_array($operation->status, ['queued', 'retry_scheduled'], true) && !$isStaleProcessing) {
                continue;
            }

            if ($operation->nextRetryAt !== null && $operation->nextRetryAt > $now) {
                continue;
            }

            $claimed = new OneCExchangeDeliveryOperation(
                id: $operation->id,
                organizationId: $operation->organizationId,
                operationKey: $operation->operationKey,
                correlationId: $operation->correlationId,
                direction: $operation->direction,
                scope: $operation->scope,
                entityType: $operation->entityType,
                entityId: $operation->entityId,
                idempotencyKey: $operation->idempotencyKey,
                status: 'processing',
                retryCount: $operation->retryCount,
                maxAttempts: $operation->maxAttempts,
                failureType: $operation->failureType,
                accountingStatus: $operation->accountingStatus,
                safePayloadPreview: $operation->safePayloadPreview,
                summary: $operation->summary,
                nextRetryAt: null,
            );
            $this->operations[$operation->id] = $claimed;

            return $claimed;
        }

        return null;
    }

    public function recordAttempt(OneCExchangeDeliveryOperation $operation, OneCExchangeDeliveryAttempt $attempt): void
    {
        $this->attempts[$operation->id][] = $attempt;
        $this->operations[$operation->id] = new OneCExchangeDeliveryOperation(
            id: $operation->id,
            organizationId: $operation->organizationId,
            operationKey: $operation->operationKey,
            correlationId: $operation->correlationId,
            direction: $operation->direction,
            scope: $operation->scope,
            entityType: $operation->entityType,
            entityId: $operation->entityId,
            idempotencyKey: $operation->idempotencyKey,
            status: $attempt->status,
            retryCount: $operation->retryCount + 1,
            maxAttempts: $operation->maxAttempts,
            failureType: $attempt->failureType,
            accountingStatus: $attempt->accountingStatus,
            safePayloadPreview: $operation->safePayloadPreview,
            summary: $operation->summary,
            nextRetryAt: $attempt->nextRetryAt,
        );
    }

    public function statusFor(int $operationId): string
    {
        return $this->operations[$operationId]->status;
    }

    /**
     * @return list<OneCExchangeDeliveryAttempt>
     */
    public function attemptsFor(int $operationId): array
    {
        return $this->attempts[$operationId] ?? [];
    }
}

final class SequenceOneCExchangeClient implements OneCExchangeClientInterface
{
    /** @var list<OneCExchangeDeliveryPayload> */
    public array $payloads = [];

    /** @var list<OneCExchangeDeliveryResult> */
    private array $results;

    /**
     * @param list<OneCExchangeDeliveryResult> $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function deliver(OneCExchangeDeliveryPayload $payload): OneCExchangeDeliveryResult
    {
        $this->payloads[] = $payload;

        return array_shift($this->results) ?? new OneCExchangeDeliveryResult(
            accepted: false,
            status: 'failed',
            retryable: false,
            failureType: 'business_validation',
            safeErrorCode: 'business_validation',
            safeErrorMessage: 'Тестовый клиент не получил сценарий ответа.',
            externalId: null,
            transportStatus: null,
            rawResponse: null,
        );
    }
}
