<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EvidenceSourceReplacementInvalidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryDocumentSourceReplacementTransaction implements DocumentSourceReplacementTransaction
{
    public string $sourceVersion = 'old';

    public function transaction(callable $callback): mixed
    {
        $before = $this->sourceVersion;
        try {
            return $callback();
        } catch (\Throwable $error) {
            $this->sourceVersion = $before;

            throw $error;
        }
    }
}

final class FailingOnceEvidenceSourceInvalidator implements EvidenceSourceReplacementInvalidator
{
    public int $calls = 0;

    public function invalidateReplacedDocumentSource(int $organizationId, int $projectId, int $sessionId, int $documentId, string $previousSourceVersion): int
    {
        if (++$this->calls === 1) {
            throw new RuntimeException('invalidation_failed');
        }

        return 1;
    }
}

final class DocumentSourceReplacementCoordinatorTest extends TestCase
{
    #[Test]
    public function invalidation_failure_rolls_back_replacement_and_retry_commits_once(): void
    {
        $transaction = new InMemoryDocumentSourceReplacementTransaction;
        $invalidator = new FailingOnceEvidenceSourceInvalidator;
        $coordinator = new DocumentSourceReplacementCoordinator($transaction, $invalidator);
        $accept = function () use ($transaction): string {
            $transaction->sourceVersion = 'new';

            return 'accepted';
        };

        try {
            $coordinator->commit(1, 10, 100, 44, 'old', 'new', $accept);
            self::fail('Invalidation failure did not roll back source replacement.');
        } catch (RuntimeException $error) {
            self::assertSame('invalidation_failed', $error->getMessage());
        }
        self::assertSame('old', $transaction->sourceVersion);

        self::assertSame('accepted', $coordinator->commit(1, 10, 100, 44, 'old', 'new', $accept));
        self::assertSame('new', $transaction->sourceVersion);
        self::assertSame(2, $invalidator->calls);
    }
}
