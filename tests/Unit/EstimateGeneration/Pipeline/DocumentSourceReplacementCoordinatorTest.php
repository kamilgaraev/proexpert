<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementPageStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceReplacementTransaction;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EvidenceSourceReplacementInvalidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

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

final class InMemoryDocumentSourceReplacementPageStore implements DocumentSourceReplacementPageStore
{
    /** @var list<array{organization_id: int, project_id: int, session_id: int, document_id: int, page_number: int, source_version: ?string, processing_unit_id: ?int}> */
    public array $pages;

    /** @var list<string> */
    public array $events = [];

    /** @param list<array{organization_id: int, project_id: int, session_id: int, document_id: int, page_number: int, source_version: ?string, processing_unit_id: ?int}> $pages */
    public function __construct(array $pages = [])
    {
        $this->pages = $pages;
    }

    public function removeStalePages(int $organizationId, int $projectId, int $sessionId, int $documentId, string $acceptedSourceVersion): int
    {
        $this->events[] = 'remove_stale_pages';
        $removed = 0;
        $this->pages = array_values(array_filter($this->pages, static function (array $page) use ($organizationId, $projectId, $sessionId, $documentId, $acceptedSourceVersion, &$removed): bool {
            $belongsToDocument = $page['organization_id'] === $organizationId
                && $page['project_id'] === $projectId
                && $page['session_id'] === $sessionId
                && $page['document_id'] === $documentId;

            if ($belongsToDocument && $page['source_version'] !== $acceptedSourceVersion) {
                $removed++;

                return false;
            }

            return true;
        }));

        return $removed;
    }

    public function reservePage(int $documentId, int $pageNumber, string $sourceVersion, int $processingUnitId): void
    {
        foreach ($this->pages as $page) {
            if ($page['document_id'] === $documentId && $page['page_number'] === $pageNumber) {
                throw new RuntimeException('page_number_reserved');
            }
        }

        $this->events[] = 'enqueue_processing_unit';
        $this->pages[] = [
            'organization_id' => 1,
            'project_id' => 10,
            'session_id' => 100,
            'document_id' => $documentId,
            'page_number' => $pageNumber,
            'source_version' => $sourceVersion,
            'processing_unit_id' => $processingUnitId,
        ];
    }
}

final class DocumentSourceReplacementCoordinatorTest extends TestCase
{
    #[Test]
    public function invalidation_failure_rolls_back_replacement_and_retry_commits_once(): void
    {
        $transaction = new InMemoryDocumentSourceReplacementTransaction;
        $invalidator = new FailingOnceEvidenceSourceInvalidator;
        $coordinator = new DocumentSourceReplacementCoordinator($transaction, $invalidator, new InMemoryDocumentSourceReplacementPageStore, new InMemoryProjectModelRepository);
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

    #[Test]
    public function source_replacement_frees_document_page_numbers_before_new_units_are_enqueued(): void
    {
        $pages = new InMemoryDocumentSourceReplacementPageStore([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 44, 'page_number' => 1, 'source_version' => 'old', 'processing_unit_id' => 501],
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 44, 'page_number' => 2, 'source_version' => 'old', 'processing_unit_id' => 502],
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => 'old', 'processing_unit_id' => 503],
        ]);
        $invalidator = new class implements EvidenceSourceReplacementInvalidator
        {
            /** @var list<array{organization_id: int, project_id: int, session_id: int, document_id: int, source_version: string}> */
            public array $calls = [];

            public function invalidateReplacedDocumentSource(int $organizationId, int $projectId, int $sessionId, int $documentId, string $previousSourceVersion): int
            {
                $this->calls[] = [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'session_id' => $sessionId,
                    'document_id' => $documentId,
                    'source_version' => $previousSourceVersion,
                ];

                return 1;
            }
        };
        $coordinator = new DocumentSourceReplacementCoordinator(new InMemoryDocumentSourceReplacementTransaction, $invalidator, $pages, new InMemoryProjectModelRepository);

        $coordinator->commit(1, 10, 100, 44, 'old', 'new', function () use ($pages): void {
            $pages->reservePage(44, 1, 'new', 601);
        });

        self::assertSame(['remove_stale_pages', 'enqueue_processing_unit'], $pages->events);
        self::assertSame([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => 'old', 'processing_unit_id' => 503],
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 44, 'page_number' => 1, 'source_version' => 'new', 'processing_unit_id' => 601],
        ], $pages->pages);
        self::assertSame([[
            'organization_id' => 1,
            'project_id' => 10,
            'session_id' => 100,
            'document_id' => 44,
            'source_version' => 'old',
        ]], $invalidator->calls);
    }

    #[Test]
    public function replacement_with_missing_previous_source_version_removes_stale_pages_only_for_its_document(): void
    {
        $pages = new InMemoryDocumentSourceReplacementPageStore([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 44, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 501],
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 502],
        ]);
        $invalidator = new class implements EvidenceSourceReplacementInvalidator
        {
            public int $calls = 0;

            public function invalidateReplacedDocumentSource(int $organizationId, int $projectId, int $sessionId, int $documentId, string $previousSourceVersion): int
            {
                $this->calls++;

                return 0;
            }
        };
        $coordinator = new DocumentSourceReplacementCoordinator(new InMemoryDocumentSourceReplacementTransaction, $invalidator, $pages, new InMemoryProjectModelRepository);

        $coordinator->commit(1, 10, 100, 44, null, 'accepted', static fn (): null => null);

        self::assertSame([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 502],
        ], $pages->pages);
        self::assertSame(0, $invalidator->calls);
    }

    #[Test]
    public function replacement_with_empty_previous_source_version_removes_stale_pages_only_for_its_document(): void
    {
        $pages = new InMemoryDocumentSourceReplacementPageStore([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 44, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 501],
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 502],
        ]);
        $invalidator = new class implements EvidenceSourceReplacementInvalidator
        {
            public int $calls = 0;

            public function invalidateReplacedDocumentSource(int $organizationId, int $projectId, int $sessionId, int $documentId, string $previousSourceVersion): int
            {
                $this->calls++;

                return 0;
            }
        };
        $coordinator = new DocumentSourceReplacementCoordinator(new InMemoryDocumentSourceReplacementTransaction, $invalidator, $pages, new InMemoryProjectModelRepository);

        $coordinator->commit(1, 10, 100, 44, '', 'accepted', static fn (): null => null);

        self::assertSame([
            ['organization_id' => 1, 'project_id' => 10, 'session_id' => 100, 'document_id' => 45, 'page_number' => 1, 'source_version' => null, 'processing_unit_id' => 502],
        ], $pages->pages);
        self::assertSame(0, $invalidator->calls);
    }
}
