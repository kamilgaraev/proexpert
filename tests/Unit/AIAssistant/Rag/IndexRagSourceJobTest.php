<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
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

        $job->handle($indexer);

        $this->assertSame([[10, 20, 'project']], $indexer->calls);
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
