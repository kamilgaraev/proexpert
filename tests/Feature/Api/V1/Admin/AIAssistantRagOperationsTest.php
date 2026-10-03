<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class AIAssistantRagOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(OrganizationEntitlementService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEffectiveModules')->andReturn(collect([
                (object) ['slug' => 'ai-assistant'], (object) ['slug' => 'project-management'],
                (object) ['slug' => 'payments'],
            ]));
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forCurrentChecks')->andReturnSelf();
            $mock->shouldReceive('canCurrent')->andReturn(true);
            $mock->shouldReceive('getUserRoles')->andReturn(collect());
        });
    }

    public function test_status_returns_current_organization_counts_without_legacy_run_history(): void
    {
        $this->withoutMiddleware();
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $foreignOrganization = Organization::factory()->create();

        $this->createIndexedSource($context->organization->id);
        $this->createIndexedSource($foreignOrganization->id);

        RagIndexRun::query()->create([
            'organization_id' => $context->organization->id,
            'status' => RagIndexRun::STATUS_FAILED,
            'mode' => RagIndexRun::MODE_ASYNC,
            'queued_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(9),
            'finished_at' => now()->subMinutes(8),
            'last_error' => 'Embedding provider unavailable',
        ]);
        RagIndexRun::query()->create([
            'organization_id' => $context->organization->id,
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'mode' => RagIndexRun::MODE_SYNC,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'indexed_chunks' => 1,
            'source_count' => 1,
            'chunk_count' => 1,
        ]);
        RagIndexRun::query()->create([
            'organization_id' => $foreignOrganization->id,
            'status' => RagIndexRun::STATUS_SUCCEEDED,
            'mode' => RagIndexRun::MODE_SYNC,
            'queued_at' => now(),
        ]);

        $response = $this
            ->actingAs($context->user->refresh(), 'api_admin')
            ->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/ai-assistant/rag/status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.source_count', 1)
            ->assertJsonPath('data.chunk_count', 1)
            ->assertJsonPath('data.latest_run', null)
            ->assertJsonPath('data.last_successful_run', null)
            ->assertJsonPath('data.last_failed_run', null);
        $projectSource = collect($response->json('data.source_catalog'))->firstWhere('type', 'project');
        $this->assertTrue($projectSource['enabled']);
        $this->assertSame(1, $projectSource['stored_count']);
    }

    public function test_reindex_queues_current_organization_run(): void
    {
        Queue::fake();
        $this->withoutMiddleware();
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        $response = $this
            ->actingAs($context->user->refresh(), 'api_admin')
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/rag/reindex', [
                'project_id' => $project->id,
                'source_type' => 'project',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.run.organization_id', $context->organization->id)
            ->assertJsonPath('data.run.project_id', $project->id)
            ->assertJsonPath('data.run.source_type', 'project')
            ->assertJsonPath('data.run.status', RagIndexRun::STATUS_QUEUED)
            ->assertJsonPath('data.run.mode', RagIndexRun::MODE_MANUAL);

        Queue::assertPushed(
            IndexRagSourceJob::class,
            static fn (IndexRagSourceJob $job): bool => $job->organizationId === $context->organization->id
                && $job->projectId === $project->id
                && $job->sourceType === 'project'
                && $job->runId !== null
        );
    }

    public function test_reindex_rejects_foreign_project_scope(): void
    {
        $this->withoutMiddleware();
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $foreignProject = Project::factory()->create();

        $response = $this
            ->actingAs($context->user->refresh(), 'api_admin')
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/rag/reindex', [
                'project_id' => $foreignProject->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_reindex_rejects_unknown_source_type(): void
    {
        $this->withoutMiddleware();
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');

        $response = $this
            ->actingAs($context->user->refresh(), 'api_admin')
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/rag/reindex', [
                'source_type' => 'missing_source',
            ]);

        $response->assertStatus(422);
    }

    public function test_rag_routes_require_explicit_permissions(): void
    {
        $statusRoute = Route::getRoutes()->getByName('admin.ai-assistant.rag.status');
        $reindexRoute = Route::getRoutes()->getByName('admin.ai-assistant.rag.reindex');

        $this->assertNotNull($statusRoute);
        $this->assertNotNull($reindexRoute);
        $this->assertContains('organization.context', $statusRoute->gatherMiddleware());
        $this->assertContains('authorize:admin.ai_assistant.rag.manage', $reindexRoute->gatherMiddleware());
    }

    private function createIndexedSource(int $organizationId): void
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $organizationId, 'is_archived' => false,
        ]));
        $source = RagSource::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'source_type' => 'project',
            'entity_type' => 'project',
            'entity_id' => (string) $project->id,
            'title' => 'Project source',
            'checksum' => str_repeat('c', 64),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $chunk = RagChunk::query()->create([
            'source_id' => $source->id,
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'chunk_index' => 0,
            'content' => 'Indexed content',
            'content_hash' => str_repeat('d', 64),
            'metadata' => [],
            'embedding_provider' => 'test',
            'embedding_model' => 'test',
            'embedding_created_at' => now(),
        ]);
        DB::update('UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?', [
            '['.implode(',', \Tests\Support\RagTestEmbedding::fromLeadingValues([1.0])).']', $chunk->id,
        ]);
    }
}
