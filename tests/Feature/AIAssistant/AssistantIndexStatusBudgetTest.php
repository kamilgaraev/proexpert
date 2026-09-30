<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantIndexStatusBudgetTest extends TestCase
{
    public function test_timeout_returns_unknown_status_and_does_not_reuse_stale_actor_data(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        Cache::put('ai-rag-coverage:'.$organization->id.':0:*:0', [
            'source_count' => 987, 'chunk_count' => 654, 'ready' => true,
            'source_catalog' => [['type' => 'private-source', 'error' => 'private-error']],
        ], 300);
        $connection = DB::connection();
        $connection->statement('SET LOCAL statement_timeout = 4000');
        $delayed = false;
        $connection->beforeExecuting(function (string $query) use (&$delayed, $connection): void {
            if (! $delayed && str_contains($query, 'accessible_sources')) {
                $delayed = true;
                $connection->select('SELECT pg_sleep(2)');
            }
        });

        $result = (new RagIndexStatusResource($this->service()->status($organization->id, $actor)))->toArray(new Request);

        $this->assertTrue($delayed);
        $this->assertFalse($result['status_available']);
        $this->assertNull($result['source_count']);
        $this->assertNull($result['chunk_count']);
        $this->assertNull($result['document_coverage']);
        $this->assertNull($result['archive_scan']);
        $this->assertFalse($result['ready']);
        $this->assertSame([], $result['source_catalog']);
        $this->assertSame('4s', $connection->selectOne("SELECT current_setting('statement_timeout') AS timeout")->timeout);
    }

    public function test_membership_denial_is_not_replaced_by_unavailable_status(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);

        $this->expectException(AuthorizationException::class);
        $this->service()->status($organization->id, $actor);
    }

    private function service(): AssistantIndexStatusService
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnFalse();
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect());
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $registry = new RagSourceRegistry([]);
        $indexer = new RagIndexer(Mockery::mock(RagEmbeddingProviderInterface::class), $registry);

        return new AssistantIndexStatusService(
            new RagCoverageService($registry, $indexer, $policy),
            app(AssistantDocumentCoverageService::class),
            new RagIndexingCoordinator($indexer),
            $policy,
            $authorization,
        );
    }
}
