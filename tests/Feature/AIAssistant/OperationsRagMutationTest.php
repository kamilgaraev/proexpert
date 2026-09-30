<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagDispatchIntent;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsWarehouseRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsQualityRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OperationsSiteRequestsRagSource;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetCategoryService;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class OperationsRagMutationTest extends TestCase
{
    public function test_actual_category_sql_insert_is_atomic_and_indexes_after_commit_without_duplicate_replay(): void
    {
        $fixture = $this->fixture();
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $service = app(AssetCategoryService::class);
        DB::beginTransaction();
        $service->resolve($fixture->organization->id, '  Оснастка  участка ');
        self::assertSame([], $jobs->items);
        self::assertSame(1, $this->runs($fixture->organization->id)->where('entity_type', 'asset_category')->count());
        DB::rollBack();
        self::assertSame(0, $this->runs($fixture->organization->id)->count());
        self::assertSame(0, DB::table('asset_categories')->where('organization_id', $fixture->organization->id)->count());
        DB::beginTransaction();
        $service->resolve($fixture->organization->id, 'Оснастка участка');
        self::assertSame([], $jobs->items);
        DB::commit();
        self::assertCount(1, $jobs->items);
        $jobs->items[0]->handle($indexer, $coordinator);
        $source = RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'asset_category')->firstOrFail();
        self::assertStringContainsString('Оснастка участка', $source->chunks()->firstOrFail()->content);
        $checksum = $source->checksum;
        $service->resolve($fixture->organization->id, ' ОСНАСТКА участка ');
        self::assertCount(1, $jobs->items);
        self::assertSame($checksum, $source->fresh()->checksum);
    }

    public function test_category_redis_failure_preserves_committed_business_row_and_recovery_indexes_pending_identity_once(): void
    {
        $fixture = $this->fixture();
        [, , $jobs] = $this->pipeline(true);
        DB::transaction(fn () => app(AssetCategoryService::class)->resolve($fixture->organization->id, 'Аварийная оснастка'));
        self::assertSame(1, DB::table('asset_categories')->where('organization_id', $fixture->organization->id)->count());
        self::assertSame([], $jobs->items);
        $run = $this->runs($fixture->organization->id)->where('entity_type', 'asset_category')->firstOrFail();
        self::assertSame(RagIndexRun::STATUS_QUEUED, $run->status);
        self::assertTrue(RagDispatchIntent::isPending($run->last_error));
        self::assertSame(RuntimeException::class, RagDispatchIntent::publicError($run->last_error));
        DB::table('ai_rag_index_runs')->where('id', $run->id)->update(['queued_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);
        [$indexer, $coordinator, $recoveredJobs] = $this->pipeline();
        self::assertSame(1, $coordinator->recoverExpiredRuns());
        self::assertSame(0, $coordinator->recoverExpiredRuns());
        self::assertCount(1, $recoveredJobs->items);
        $recoveredJobs->items[0]->handle($indexer, $coordinator);
        self::assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        self::assertSame(1, RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'asset_category')->count());
    }

    public function test_real_quality_ledger_uuid_insert_has_transactional_pending_identity_and_rollback_has_no_job(): void
    {
        $fixture = $this->fixture();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $create = fn () => Model::withoutEvents(fn () => app(QualityDefectService::class)->create($fixture->organization->id, $fixture->owner->id,
            ['project_id' => $project->id, 'title' => 'Проверка участка', 'severity' => 'major', 'inspection_required' => true]));
        DB::beginTransaction();
        $defect = $create();
        $event = DB::table('quality_defect_flow_events')->where('quality_defect_id', $defect->id)->first();
        self::assertNotNull($event);
        self::assertSame(1, $this->runs($fixture->organization->id)->where('entity_type', 'quality_defect_flow_event')->where('entity_id', $event->event_id)->count());
        self::assertSame([], $jobs->items);
        DB::rollBack();
        self::assertSame(0, $this->runs($fixture->organization->id)->count());
        self::assertSame(0, DB::table('quality_defect_flow_events')->where('quality_defect_id', $defect->id)->count());
        DB::beginTransaction();
        $defect = $create();
        $eventId = DB::table('quality_defect_flow_events')->where('quality_defect_id', $defect->id)->value('event_id');
        self::assertSame([], $jobs->items);
        DB::commit();
        self::assertNotEmpty($jobs->items);
        foreach ($jobs->items as $job) { $job->handle($indexer, $coordinator); }
        self::assertSame(1, RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'quality_defect_flow_event')->where('entity_id', $eventId)->count());
    }

    public function test_real_calendar_sql_delete_preserves_identity_for_pruning_and_rollback_keeps_index(): void
    {
        $fixture = $this->fixture();
        Cache::setDefaultDriver('array');
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        [$request, $calendar] = Model::withoutEvents(function () use ($fixture, $project): array {
            $request = SiteRequest::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'user_id' => $fixture->owner->id, 'title' => 'Заявка', 'status' => 'draft', 'priority' => 'medium', 'request_type' => 'info_request']);
            $calendar = SiteRequestCalendarEvent::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'site_request_id' => $request->id, 'event_type' => 'deadline', 'title' => 'Срок заявки', 'color' => '#333333', 'start_date' => now()->toDateString()]);
            return [$request, $calendar];
        });
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $indexer->indexEntity($fixture->organization->id, 'operations_site_requests', 'site_request_calendar_event', $calendar->id);
        $source = RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'site_request_calendar_event')->where('entity_id', (string) $calendar->id)->firstOrFail();
        $sourceId = $source->id;
        DB::beginTransaction();
        app(SiteRequestCalendarService::class)->deleteCalendarEvent($request);
        DB::rollBack();
        self::assertSame([], $jobs->items);
        self::assertTrue(SiteRequestCalendarEvent::query()->whereKey($calendar->id)->exists());
        self::assertTrue(RagSource::query()->whereKey($sourceId)->exists());
        DB::beginTransaction();
        app(SiteRequestCalendarService::class)->deleteCalendarEvent($request);
        self::assertSame([], $jobs->items);
        DB::commit();
        self::assertFalse(SiteRequestCalendarEvent::query()->whereKey($calendar->id)->exists());
        self::assertCount(1, $jobs->items);
        $jobs->items[0]->handle($indexer, $coordinator);
        self::assertFalse(RagSource::query()->whereKey($sourceId)->exists());
        self::assertSame(0, DB::table('ai_rag_chunks')->where('source_id', $sourceId)->count());
    }

    public function test_actual_identifier_bulk_sql_update_queues_all_63_current_ids_after_commit_and_none_on_rollback(): void
    {
        $fixture = $this->fixture();
        $warehouse = Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse::query()->create(
            ['organization_id' => $fixture->organization->id, 'name' => 'Склад оснастки', 'code' => 'bulk', 'warehouse_type' => 'central', 'is_active' => true]));
        $ids = [];
        for ($index = 0; $index < 63; $index++) {
            $ids[] = Model::withoutEvents(fn () => \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier::query()->create(
                ['organization_id' => $fixture->organization->id, 'warehouse_id' => $warehouse->id, 'identifier_type' => 'internal',
                    'code' => 'BULK-'.$index, 'entity_type' => 'warehouse', 'entity_id' => $warehouse->id, 'status' => 'active', 'is_primary' => true]))->id;
        }
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $controller = app(\App\BusinessModules\Features\BasicWarehouse\Controllers\WarehouseIdentifierController::class);
        $clear = new \ReflectionMethod($controller, 'clearPrimaryIdentifier');
        DB::beginTransaction();
        $clear->invoke($controller, $fixture->organization->id, 'warehouse', $warehouse->id);
        self::assertSame([], $jobs->items);
        self::assertSame(63, $this->runs($fixture->organization->id)->where('entity_type', 'warehouse_identifier')->count());
        DB::rollBack();
        self::assertSame(0, $this->runs($fixture->organization->id)->count());
        self::assertSame(63, DB::table('warehouse_identifiers')->whereIn('id', $ids)->where('is_primary', true)->count());
        DB::beginTransaction();
        $clear->invoke($controller, $fixture->organization->id, 'warehouse', $warehouse->id);
        self::assertSame([], $jobs->items);
        DB::commit();
        self::assertCount(63, $jobs->items);
        foreach ($jobs->items as $job) { $job->handle($indexer, $coordinator); }
        self::assertSame(0, DB::table('warehouse_identifiers')->whereIn('id', $ids)->where('is_primary', true)->count());
        $sources = RagSource::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'warehouse_identifier')->get();
        self::assertSame(array_map('strval', $ids), $sources->sortBy(fn (RagSource $source): int => (int) $source->entity_id)->pluck('entity_id')->values()->all());
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    private function runs(int $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        return RagIndexRun::query()->where('organization_id', $organizationId);
    }

    private function pipeline(bool $outage = false): array
    {
        $jobs = new class { public array $items = []; };
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexRagSourceJob $job) use ($jobs, $outage): null {
            if ($outage) { throw new RuntimeException('queue_unavailable'); }
            $jobs->items[] = $job;
            return null;
        });
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('embed')->willReturn(RagTestEmbedding::fromLeadingValues([1.0]));
        $embedding->method('provider')->willReturn('test');
        $embedding->method('model')->willReturn('operations-mutation');
        $embedding->method('dimensions')->willReturn(RagTestEmbedding::DIMENSIONS);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([new OperationsWarehouseRagSource, new OperationsQualityRagSource, new OperationsSiteRequestsRagSource]));
        $coordinator = new RagIndexingCoordinator($indexer, new RagJobDispatcher($bus, new NullLogger));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        return [$indexer, $coordinator, $jobs];
    }
}
