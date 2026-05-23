<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AIAssistantRagBackfillCommandTest extends TestCase
{
    public function test_sync_backfill_calls_indexer_with_requested_scope(): void
    {
        $indexer = new BackfillCommandRecordingRagIndexer(7);
        $this->app->instance(RagIndexer::class, $indexer);

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 10,
            '--project_id' => 20,
            '--source_type' => 'project',
            '--sync' => true,
        ])
            ->expectsOutput('Indexed RAG chunks: 7')
            ->assertExitCode(0);

        $this->assertSame([[10, 20, 'project']], $indexer->calls);
    }

    public function test_async_backfill_dispatches_index_job_with_requested_scope(): void
    {
        Queue::fake();

        $this->artisan('ai-assistant:rag-backfill', [
            'organization_id' => 11,
            '--project_id' => 22,
            '--source_type' => 'schedule',
        ])
            ->expectsOutput('Queued RAG indexing job.')
            ->assertExitCode(0);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === 11
                && $job->projectId === 22
                && $job->sourceType === 'schedule'
                && $job->queue === 'ai-rag'
        );
    }
}

final class BackfillCommandRecordingRagIndexer extends RagIndexer
{
    /**
     * @var array<int, array{0: int, 1: int|null, 2: string|null}>
     */
    public array $calls = [];

    public function __construct(private readonly int $indexedCount)
    {
    }

    public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null): int
    {
        $this->calls[] = [$organizationId, $projectId, $sourceType];

        return $this->indexedCount;
    }
}
