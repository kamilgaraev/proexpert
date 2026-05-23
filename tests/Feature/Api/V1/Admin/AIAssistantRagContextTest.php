<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AIAssistantRagContextTest extends TestCase
{
    public function test_admin_chat_uses_rag_context_and_returns_sources_metadata(): void
    {
        config()->set('ai-assistant.rag.enabled', true);
        config()->set('ai-assistant.rag.max_chunks', 8);
        config()->set('ai-assistant.rag.min_similarity', 0.1);

        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Letter A',
            'is_archived' => false,
        ]);
        $context->user->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->indexRagChunk($context->organization->id, $project->id);

        $llmProvider = new FeatureRagLlmProvider;
        $this->app->instance(LLMProviderInterface::class, $llmProvider);
        $this->app->instance(RagEmbeddingProviderInterface::class, new FeatureRagEmbeddingProvider);
        $this->mockAssistantDependencies();

        $response = $this
            ->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/ai-assistant/chat', [
                'message' => 'What blocks project Letter A?',
                'context' => [
                    'source_module' => 'projects',
                    'entity_refs' => [
                        [
                            'type' => 'project',
                            'id' => $project->id,
                            'label' => 'Letter A',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.message.metadata.rag_context.used', true);
        $response->assertJsonPath('data.message.metadata.rag_context.sources.0.title', 'Letter A risk memo');
        $response->assertJsonPath('data.message.metadata.rag_context.sources.0.entity_type', 'project');
        $response->assertJsonPath('data.message.metadata.rag_context.sources.0.project_id', $project->id);

        $prompt = collect($llmProvider->lastMessages)
            ->pluck('content')
            ->filter(static fn (mixed $content): bool => is_string($content))
            ->implode("\n");

        self::assertStringContainsString('ProHelper context:', $prompt);
        self::assertStringContainsString('Delayed materials block facade works.', $prompt);
        self::assertStringContainsString('[1] Letter A risk memo', $prompt);
    }

    private function indexRagChunk(int $organizationId, int $projectId): void
    {
        $indexer = new RagIndexer(
            new FeatureRagEmbeddingProvider,
            new RagSourceRegistry([])
        );

        $indexer->indexChunk(new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: 'project',
            entityType: 'project',
            entityId: $projectId,
            title: 'Letter A risk memo',
            content: 'Delayed materials block facade works.',
            metadata: ['source' => 'feature-test'],
            updatedAt: now()
        ));
    }

    private function mockAssistantDependencies(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AIPermissionChecker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canUseAssistant')->andReturn(true);
            $mock->shouldReceive('canAccessOrganizationConversationsInAdmin')->andReturn(true);
            $mock->shouldReceive('isMutationTool')->andReturn(false);
            $mock->shouldReceive('canExecuteTool')->andReturn(true);
        });

        $this->mock(UsageTracker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canMakeRequest')->andReturn(true);
            $mock->shouldReceive('calculateCost')->andReturn(0.0);
            $mock->shouldReceive('trackRequest')->andReturnNull();
            $mock->shouldReceive('getUsageStats')->andReturn([
                'requests_used' => 1,
                'requests_limit' => 100,
            ]);
        });

        $this->mock(ContextBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('buildContext')->andReturn([
                'intent' => 'analyze',
                'project' => 'Letter A',
            ]);
            $mock->shouldReceive('buildSystemPrompt')->andReturn('Answer from confirmed workspace data.');
        });

        $this->mock(LoggingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('business')->andReturnNull();
            $mock->shouldReceive('technical')->andReturnNull();
            $mock->shouldReceive('audit')->andReturnNull();
            $mock->shouldReceive('access')->andReturnNull();
        });
    }
}

final class FeatureRagLlmProvider implements LLMProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $lastMessages = [];

    public function chat(array $messages, array $options = []): array
    {
        $this->lastMessages = $messages;

        return [
            'role' => 'assistant',
            'content' => 'Project Letter A is blocked by delayed materials. [1]',
            'tokens_used' => 42,
            'model' => 'feature-rag-llm',
        ];
    }

    public function countTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getModel(): string
    {
        return 'feature-rag-llm';
    }
}

final class FeatureRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        return [1.0, 0.0, 0.0];
    }

    public function provider(): string
    {
        return 'feature';
    }

    public function model(): string
    {
        return 'feature-rag-embedding';
    }

    public function dimensions(): int
    {
        return 3;
    }
}
