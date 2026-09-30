<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantIndexStatusBudgetTest extends TestCase
{
    private int $moduleReads = 0;

    public function test_status_reads_current_modules_once_and_returns_real_empty_counts(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $service = $this->service();
        $start = hrtime(true);
        $status = $service->status($organization->id, $actor);
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        $this->assertTrue($status['status_available']);
        $this->assertSame(0, $status['source_count']);
        $this->assertSame(0, $status['document_coverage']['total']);
        $this->assertSame(1, $this->moduleReads, 'Repeated current module reads; status elapsed '.round($elapsed).'ms');
    }

    public function test_current_role_status_keeps_visible_counts_with_one_entitlement_read(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id, 'is_archived' => false]));
        RagSource::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'title' => 'Проект', 'checksum' => hash('sha256', 'fixture'),
        ]);
        $service = app(AssistantIndexStatusService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        try {
            $status = $service->status($fixture->organization->id, $fixture->owner);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $metrics = [
            'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
            'sql_count' => count($queries),
            'sql_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'timeout_set_count' => count(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'SET LOCAL statement_timeout'))),
            'schema_query_count' => count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'pg_catalog') || str_contains($query['query'], 'information_schema'))),
        ];
        if (getenv('MOST_RAG_STATUS_PROFILE') === '1') { fwrite(STDERR, 'RAG status fixture '.json_encode($metrics).PHP_EOL); }
        $this->assertTrue($status['status_available'], json_encode($metrics));
        $this->assertSame(1, $status['source_count']);
        $this->assertSame(0, $status['chunk_count']);
        $subscriptions = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'organization_package_subscriptions'));
        $this->assertLessThan(4, count($subscriptions));
    }

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

    public function test_budget_is_checked_during_source_permission_iteration_and_scope_is_restored(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);
        $checks = 0;

        try {
            $policy->withCurrentChecks($actor, $organization->id, fn (): array => $policy->allowedSourceTypes($actor, $organization->id),
                checkpoint: function () use (&$checks): void {
                    if (++$checks === 4) { throw new RagStatusBudgetExceeded; }
                });
            $this->fail('Permission iteration must check its overall deadline');
        } catch (RagStatusBudgetExceeded) {
            $this->assertSame(4, $checks);
        }

        $this->assertTrue($policy->belongsToOrganization($actor, $organization->id));
        $this->assertSame(4, $checks);
    }

    public function test_document_owner_check_propagates_overall_budget_expiry(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true]);
        $this->service();
        $policy = app(AssistantDataAccessPolicy::class);

        $this->expectException(RagStatusBudgetExceeded::class);
        $policy->withCurrentChecks($actor, $organization->id,
            fn (): bool => app(AssistantDocumentCoverageService::class)->canManageSettings($organization->id, $actor),
            checkpoint: static function (): void { throw new RagStatusBudgetExceeded; });
    }

    private function service(): AssistantIndexStatusService
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnFalse();
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(function () {
            $this->moduleReads++;

            return collect();
        });
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
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
