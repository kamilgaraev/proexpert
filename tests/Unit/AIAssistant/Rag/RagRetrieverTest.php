<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Enums\UserProjectAccessMode;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Tests\TestCase;

class RagRetrieverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-assistant.rag.max_chunks', 8);
        config()->set('ai-assistant.rag.min_similarity', 0.1);
    }

    public function test_search_filters_by_organization_and_assigned_projects_and_orders_by_similarity(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ASSIGNED_PROJECTS->value
        );
        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->indexChunk($organization->id, $projectA->id, 'Allowed close', 'allowed close content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $projectA->id, 'Allowed second', 'allowed second content', [0.8, 0.2, 0.0]);
        $this->indexChunk($organization->id, $projectB->id, 'Blocked project', 'blocked content', [1.0, 0.0, 0.0]);
        $this->indexChunk($foreignOrganization->id, $foreignProject->id, 'Foreign org', 'foreign content', [1.0, 0.0, 0.0]);

        $provider = new RetrieverEmbeddingProvider([1.0, 0.0, 0.0]);
        $results = $this->retriever($provider)->search('risk on project', $organization->id, $user);

        $this->assertCount(2, $results);
        $this->assertSame('Allowed close', $results[0]->title);
        $this->assertSame('Allowed second', $results[1]->title);
        $this->assertGreaterThanOrEqual($results[1]->similarity, $results[0]->similarity);
        $this->assertSame(RagEmbeddingProviderInterface::PURPOSE_QUERY, $provider->lastPurpose);
    }

    public function test_search_excludes_chunks_below_similarity_threshold(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );
        config()->set('ai-assistant.rag.min_similarity', 0.9);

        $this->indexChunk($organization->id, $project->id, 'Close source', 'close content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $project->id, 'Distant source', 'distant content', [0.0, 1.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->search('close query', $organization->id, $user);

        $this->assertCount(1, $results);
        $this->assertSame('Close source', $results[0]->title);
    }

    public function test_search_applies_request_project_filter_after_access_scope(): void
    {
        [$organization, $user, $projectA, $projectB] = $this->createOrganizationUserWithProjects(
            UserProjectAccessMode::ASSIGNED_PROJECTS->value
        );
        $user->assignedProjects()->attach($projectA->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->indexChunk($organization->id, $projectA->id, 'Allowed project', 'allowed content', [1.0, 0.0, 0.0]);
        $this->indexChunk($organization->id, $projectB->id, 'Blocked project', 'blocked content', [1.0, 0.0, 0.0]);

        $allowed = $this->retriever([1.0, 0.0, 0.0])->search(
            'project query',
            $organization->id,
            $user,
            ['project_id' => $projectA->id]
        );
        $blocked = $this->retriever([1.0, 0.0, 0.0])->search(
            'project query',
            $organization->id,
            $user,
            ['project_id' => $projectB->id]
        );

        $this->assertCount(1, $allowed);
        $this->assertSame('Allowed project', $allowed[0]->title);
        $this->assertSame([], $blocked);
    }

    public function test_search_returns_compact_evidence_safe_excerpt(): void
    {
        [$organization, $user, $project] = $this->createOrganizationUserWithOneProject(
            UserProjectAccessMode::ALL_PROJECTS->value
        );
        $content = str_repeat("Long line with useful evidence.\n", 30);

        $this->indexChunk($organization->id, $project->id, 'Long source', $content, [1.0, 0.0, 0.0]);

        $results = $this->retriever([1.0, 0.0, 0.0])->search('long query', $organization->id, $user);

        $this->assertCount(1, $results);
        $this->assertLessThanOrEqual(360, mb_strlen($results[0]->excerpt));
        $this->assertStringNotContainsString("\n", $results[0]->excerpt);
        $this->assertStringContainsString('Long line with useful evidence.', $results[0]->excerpt);
    }

    private function retriever(array|RetrieverEmbeddingProvider $queryEmbedding): RagRetriever
    {
        $provider = $queryEmbedding instanceof RetrieverEmbeddingProvider
            ? $queryEmbedding
            : new RetrieverEmbeddingProvider($queryEmbedding);

        return new RagRetriever(
            $provider,
            app(UserProjectAccessService::class)
        );
    }

    private function indexChunk(
        int $organizationId,
        int $projectId,
        string $title,
        string $content,
        array $embedding
    ): void {
        $indexer = new RagIndexer(
            new RetrieverEmbeddingProvider($embedding),
            new RagSourceRegistry([])
        );

        $indexer->indexChunk(new RagChunkData(
            organizationId: $organizationId,
            projectId: $projectId,
            sourceType: 'project',
            entityType: 'project',
            entityId: $projectId.'-'.$title,
            title: $title,
            content: $content,
            metadata: ['title' => $title],
            updatedAt: now()
        ));
    }

    /**
     * @return array{0: Organization, 1: User, 2: Project}
     */
    private function createOrganizationUserWithOneProject(string $mode): array
    {
        [$organization, $user, $projectA] = $this->createOrganizationUserWithProjects($mode);

        return [$organization, $user, $projectA];
    }

    /**
     * @return array{0: Organization, 1: User, 2: Project, 3: Project}
     */
    private function createOrganizationUserWithProjects(string $mode): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $user->organizations()->attach($organization->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => $mode,
        ]);

        $projectA = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);
        $projectB = Project::factory()->create([
            'organization_id' => $organization->id,
            'is_archived' => false,
        ]);

        return [$organization, $user, $projectA, $projectB];
    }
}

final class RetrieverEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public ?string $lastPurpose = null;

    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(private readonly array $embedding)
    {
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->lastPurpose = $purpose;

        return $this->embedding;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function dimensions(): int
    {
        return count($this->embedding);
    }
}
