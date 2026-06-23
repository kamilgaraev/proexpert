<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use PHPUnit\Framework\TestCase;

class IndexRagSourceJobTest extends TestCase
{
    public function test_job_calls_indexer_with_requested_scope(): void
    {
        $job = new IndexRagSourceJob(10, 20, 'project');
        $indexer = new RecordingRagIndexer();

        $this->assertSame(10, $job->organizationId);
        $this->assertSame(20, $job->projectId);
        $this->assertSame('project', $job->sourceType);
        $this->assertSame('redis_ai_rag', $job->connection);
        $this->assertSame('ai-rag', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(7200, $job->timeout);
        $this->assertTrue($job->failOnTimeout);

        $job->handle($indexer);

        $this->assertSame([[10, 20, 'project']], $indexer->calls);
    }

    public function test_job_does_not_index_when_run_cannot_be_marked_running(): void
    {
        $job = new IndexRagSourceJob(10, 20, 'project', 30);
        $indexer = new RecordingRagIndexer();
        $coordinator = new TestRagIndexingCoordinator();
        $coordinator->markRunningResult = null;

        $job->handle($indexer, $coordinator);

        $this->assertSame([30], $coordinator->markRunningCalls);
        $this->assertSame([], $indexer->calls);
    }

    public function test_legacy_org_wide_project_scoped_job_is_split_before_indexing(): void
    {
        $job = new IndexRagSourceJob(10, null, 'estimate', 30);
        $indexer = new RecordingRagIndexer();
        $coordinator = new TestRagIndexingCoordinator();
        $run = new RagIndexRun();
        $run->mode = RagIndexRun::MODE_SCHEDULED;
        $coordinator->markRunningResult = $run;
        $coordinator->shouldSplit = true;

        $job->handle($indexer, $coordinator);

        $this->assertSame([30], $coordinator->markRunningCalls);
        $this->assertSame(['estimate'], $coordinator->shouldSplitCalls);
        $this->assertSame([[30, 10, 'estimate']], $coordinator->splitCalls);
        $this->assertSame([], $indexer->calls);
    }

    public function test_job_keeps_org_wide_manual_run_on_direct_indexing_path(): void
    {
        $job = new IndexRagSourceJob(10, null, 'estimate', 30);
        $indexer = new RecordingRagIndexer();
        $coordinator = new TestRagIndexingCoordinator();
        $run = new RagIndexRun();
        $run->mode = RagIndexRun::MODE_MANUAL;
        $coordinator->markRunningResult = $run;
        $coordinator->shouldSplit = true;

        $job->handle($indexer, $coordinator);

        $this->assertSame([30], $coordinator->markRunningCalls);
        $this->assertSame([], $coordinator->splitCalls);
        $this->assertSame([[30, 1]], $coordinator->markSucceededCalls);
        $this->assertSame([[10, null, 'estimate']], $indexer->calls);
    }
}

final class RecordingRagIndexer extends RagIndexer
{
    /**
     * @var array<int, array{0: int, 1: int|null, 2: string|null}>
     */
    public array $calls = [];

    public function __construct()
    {
    }

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null): int
    {
        $this->calls[] = [$organizationId, $projectId, $sourceType];

        return 1;
    }
}

final class TestRagIndexingCoordinator extends RagIndexingCoordinator
{
    public ?RagIndexRun $markRunningResult = null;

    public bool $shouldSplit = false;

    /** @var array<int, int> */
    public array $markRunningCalls = [];

    /** @var array<int, string> */
    public array $shouldSplitCalls = [];

    /** @var array<int, array{0: int, 1: int, 2: string}> */
    public array $splitCalls = [];

    /** @var array<int, array{0: int, 1: int}> */
    public array $markSucceededCalls = [];

    public function __construct()
    {
        parent::__construct(new RecordingRagIndexer());
    }

    public function markRunning(int $runId): ?RagIndexRun
    {
        $this->markRunningCalls[] = $runId;

        return $this->markRunningResult;
    }

    public function shouldSplitOrganizationSourceByProjects(string $sourceType): bool
    {
        $this->shouldSplitCalls[] = $sourceType;

        return $this->shouldSplit;
    }

    public function splitOrganizationSourceRunByProjects(
        int $runId,
        int $organizationId,
        string $sourceType
    ): int {
        $this->splitCalls[] = [$runId, $organizationId, $sourceType];

        return 1;
    }

    public function markSucceeded(int $runId, int $indexedChunks): ?RagIndexRun
    {
        $this->markSucceededCalls[] = [$runId, $indexedChunks];

        return new RagIndexRun();
    }
}
