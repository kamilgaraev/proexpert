<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalSection;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalLineItem;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use App\Models\Project;
use Illuminate\Support\Str;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContactPoint;
use App\BusinessModules\Features\Crm\Services\CrmDuplicateService;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSalesRagMutationTest extends TestCase
{
    public function test_actual_contact_detail_replacement_invalidates_deleted_rows_after_commit(): void
    {
        [$fixture, $company, $points, $other] = $this->fixture(3);
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        $this->replace($fixture, $company);
        self::assertSame(0, CrmContactPoint::query()->whereIn('id', $points)->count());
        self::assertTrue(CrmContactPoint::query()->whereKey($other->id)->exists());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        self::assertEqualsCanonicalizing($points, $this->runs($fixture)->pluck('entity_id')->all());
        foreach ($this->runs($fixture)->get() as $run) {
            Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id);
        }
    }

    public function test_bulk_reparent_keeps_every_native_uuid_beyond_fifty_and_excludes_unrelated_rows(): void
    {
        [$fixture, $duplicate, $points, $other] = $this->fixture(63);
        $master = Model::withoutEvents(fn () => CrmCompany::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Основная компания']));
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        app(CrmDuplicateService::class)->merge($fixture->organization->id, 'companies', $master->id, $duplicate->id, 'QA', $fixture->owner->id);
        self::assertSame(63, CrmContactPoint::query()->where('company_id', $master->id)->count());
        self::assertSame($other->company_id, $other->fresh()->company_id);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        self::assertEqualsCanonicalizing($points, $this->runs($fixture)->pluck('entity_id')->all());
        self::assertSame(63, $this->runs($fixture)->count());
    }

    public function test_outer_rollback_restores_native_rows_and_removes_runs_and_jobs(): void
    {
        [$fixture, $company, $points] = $this->fixture(3);
        Queue::fake([IndexRagSourceJob::class]);
        $before = RagIndexRun::query()->where('organization_id', $fixture->organization->id)->count();
        DB::beginTransaction();
        $this->replace($fixture, $company);
        self::assertSame(0, CrmContactPoint::query()->whereIn('id', $points)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();
        self::assertSame(3, CrmContactPoint::query()->whereIn('id', $points)->count());
        self::assertSame($before, RagIndexRun::query()->where('organization_id', $fixture->organization->id)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_actual_postgres_queue_failure_isolated_by_savepoint_preserves_business_commit(): void
    {
        [$fixture, $company, $points] = $this->fixture(3);
        Queue::fake([IndexRagSourceJob::class]);
        $coordinator = new SalesMutationSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        DB::beginTransaction();
        $this->replace($fixture, $company);
        self::assertGreaterThan(0, $coordinator->failures);
        self::assertSame('22012', $coordinator->sqlState);
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
        DB::commit();
        self::assertSame(0, CrmContactPoint::query()->whereIn('id', $points)->count());
        self::assertSame(0, $this->runs($fixture)->count());
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
    }

    public function test_version_row_replacement_queues_all_deleted_rows_despite_non_id_relation_order(): void
    {
        [$fixture] = $this->fixture(0);
        [$proposal, $version, $section, $ids] = Model::withoutEvents(function () use ($fixture): array {
            $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $proposal = CommercialProposal::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'number' => 'QA-'.Str::uuid(), 'title' => 'Предложение QA']);
            $version = CommercialProposalVersion::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'version_number' => 1, 'title' => 'Версия QA', 'content_hash' => str_repeat('a', 64)]);
            $section = CommercialProposalSection::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'commercial_proposal_version_id' => $version->id, 'title' => 'Раздел QA']);
            $ids = [];
            for ($i = 0; $i < 63; $i++) {
                $ids[] = CommercialProposalLineItem::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'commercial_proposal_version_id' => $version->id, 'commercial_proposal_section_id' => $section->id, 'title' => 'Позиция '.$i, 'sort_order' => 63 - $i])->id;
            }
            return [$proposal, $version, $section, $ids];
        });
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        $method = new \ReflectionMethod(CommercialProposalService::class, 'replaceVersionRows');
        $method->invoke(app(CommercialProposalService::class), $proposal, $version, []);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        self::assertSame(0, CommercialProposalLineItem::query()->whereIn('id', $ids)->count());
        DB::commit();
        $runs = RagIndexRun::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'commercial_proposal_line_item');
        self::assertEqualsCanonicalizing($ids, $runs->pluck('entity_id')->all());
        self::assertSame(63, $runs->count());
        self::assertSame([$proposal->project_id], $runs->pluck('project_id')->unique()->all());
        self::assertSame(1, RagIndexRun::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'commercial_proposal_section')->where('entity_id', $section->id)->count());
    }

    public function test_query_builder_process_append_queues_actual_identity_once_and_waits_for_owner_commit(): void
    {
        $fixture = (new \Tests\Support\Procurement\Reporting\Award\ProcurementAwardPostgresFixture(DB::connection()))->create('sales-process-'.Str::lower(Str::random(10)));
        $dimensions = \App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => 'procurement-process-dimensions.v1', 'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'], 'purchase_request_id' => $fixture['purchase_request_id'],
            'purchase_request_line_id' => $fixture['purchase_request_line_id'], 'quality_status' => 'PARTIAL', 'gap_codes' => ['missing_policy_version']]);
        $event = new \App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition(
            eventCode: \App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode::REQUEST_CREATED,
            organizationId: $fixture['organization_id'], projectId: $fixture['project_id'], purchaseRequestId: $fixture['purchase_request_id'],
            purchaseRequestLineId: $fixture['purchase_request_line_id'], occurredAt: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            sourceKind: 'sales_mutation_qa', sourceId: $fixture['purchase_request_id'], dimensionSnapshot: $dimensions, actorId: $fixture['user_id']);
        $store = new \App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\EloquentProcurementProcessEventStore(new \App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementEventIdempotencyGuard());
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        $store->append($event);
        $store->append($event);
        $id = DB::table('procurement_process_events')->where($event->idempotencyIdentity())->value('id');
        self::assertNotNull($id);
        self::assertSame(1, DB::table('procurement_process_events')->where($event->idempotencyIdentity())->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::commit();
        $runs = RagIndexRun::query()->where('organization_id', $fixture['organization_id'])->where('entity_type', 'procurement_process_event');
        self::assertSame([(string) $id], $runs->pluck('entity_id')->all());
        self::assertSame([$fixture['project_id']], $runs->pluck('project_id')->all());
        Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->runId === $runs->firstOrFail()->id);
    }

    private function replace(AssistantRealAuthorizationFixture $fixture, CrmCompany $company): void
    {
        app(CrmRegistryService::class)->updateCompany($fixture->organization->id, $company->id, ['contact_points' => []], $fixture->owner->id);
    }

    private function runs(AssistantRealAuthorizationFixture $fixture): \Illuminate\Database\Eloquent\Builder
    {
        return RagIndexRun::query()->where('organization_id', $fixture->organization->id)->where('entity_type', 'crm_contact_point');
    }

    private function fixture(int $count): array
    {
        return Model::withoutEvents(function () use ($count): array {
            $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
            $company = CrmCompany::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Компания QA']);
            $otherCompany = CrmCompany::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'Другая компания']);
            $points = [];
            for ($i = 0; $i < $count; $i++) {
                $points[] = CrmContactPoint::query()->create(['organization_id' => $fixture->organization->id, 'company_id' => $company->id, 'point_type' => 'email', 'value' => 'qa'.$i.'@example.test', 'normalized_value' => 'qa'.$i.'@example.test'])->id;
            }
            $other = CrmContactPoint::query()->create(['organization_id' => $fixture->organization->id, 'company_id' => $otherCompany->id, 'point_type' => 'email', 'value' => 'other@example.test', 'normalized_value' => 'other@example.test']);
            return [$fixture, $company, $points, $other];
        });
    }
}

final class SalesMutationSqlFailureCoordinator extends RagIndexingCoordinator
{
    public int $failures = 0;
    public ?string $sqlState = null;

    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        if ($entityType === 'crm_contact_point') {
            $this->failures++;
            try { DB::select('SELECT 1 / 0'); }
            catch (QueryException $exception) { $this->sqlState = $exception->errorInfo[0] ?? null; throw $exception; }
        }
        return parent::queueEntity($organizationId, $projectId, $sourceType, $entityType, $entityId);
    }
}
