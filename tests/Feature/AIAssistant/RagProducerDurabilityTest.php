<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateReferenceRagSource;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateTemplate;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class RagProducerDurabilityTest extends TestCase
{
    public function test_estimate_mutation_persists_run_before_commit_and_rollback_removes_domain_change_and_run(): void
    {
        Queue::fake([IndexRagSourceJob::class]);
        [$organization, $project] = $this->projectFixture();
        $estimate = Estimate::withoutEvents(fn (): Estimate => Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'RAG-DURABILITY',
            'name' => 'Исходная смета',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
        ]));

        DB::beginTransaction();
        $estimate->update(['name' => 'Изменённая смета']);

        $run = RagIndexRun::query()->where('organization_id', $organization->id)
            ->where('source_type', 'estimate')->where('entity_type', 'estimate')
            ->where('entity_id', (string) $estimate->id)->firstOrFail();
        $this->assertSame('Изменённая смета', $estimate->fresh()->name);
        Queue::assertNotPushed(IndexRagSourceJob::class);

        DB::rollBack();

        $this->assertSame('Исходная смета', $estimate->fresh()->name);
        $this->assertDatabaseMissing('ai_rag_index_runs', ['id' => $run->id]);
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_project_mutation_propagates_queue_persistence_failure_and_keeps_outer_transaction_usable(): void
    {
        [$organization, $project] = $this->projectFixture();
        $coordinator = new RagProducerSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);

        $originalName = $project->name;
        DB::beginTransaction();
        try {
            $project->update(['name' => 'Изменённый проект']);
            $this->fail('Ожидалось исключение при записи RAG intent');
        } catch (QueryException $exception) {
            $this->assertSame('22012', $exception->errorInfo[0] ?? null);
        }

        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
        $this->assertSame(0, RagIndexRun::query()->where('organization_id', $organization->id)
            ->where('entity_type', 'project')->where('entity_id', (string) $project->id)->count());
        DB::rollBack();
        $this->assertSame($originalName, $project->fresh()->name);
    }

    public function test_section_service_rolls_back_update_when_rag_intent_persistence_fails(): void
    {
        [$organization, $project] = $this->projectFixture();
        $estimate = Estimate::withoutEvents(fn (): Estimate => Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'RAG-SECTION-FAILURE',
            'name' => 'Смета раздела',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
        ]));
        $section = EstimateSection::withoutEvents(fn (): EstimateSection => EstimateSection::query()->create([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'name' => 'Исходный раздел',
        ]));
        $this->app->instance(RagIndexingCoordinator::class,
            new RagProducerSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class)));

        try {
            app(EstimateSectionService::class)->updateSection($section, ['name' => 'Изменённый раздел']);
            $this->fail('Ожидалось исключение при записи RAG intent');
        } catch (QueryException $exception) {
            $this->assertSame('22012', $exception->errorInfo[0] ?? null);
        }

        $this->assertSame('Исходный раздел', $section->fresh()->name);
    }

    public function test_estimate_service_rolls_back_delete_when_rag_intent_persistence_fails(): void
    {
        [$organization, $project] = $this->projectFixture();
        $estimate = Estimate::withoutEvents(fn (): Estimate => Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'RAG-DELETE-FAILURE',
            'name' => 'Смета для удаления',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => now()->toDateString(),
        ]));
        $this->app->instance(RagIndexingCoordinator::class,
            new RagProducerSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class)));

        try {
            app(EstimateService::class)->delete($estimate);
            $this->fail('Ожидалось исключение при записи RAG intent');
        } catch (QueryException $exception) {
            $this->assertSame('22012', $exception->errorInfo[0] ?? null);
        }

        $this->assertNull($estimate->fresh()->deleted_at);
    }

    public function test_failed_redis_dispatch_keeps_queued_run_for_recovery(): void
    {
        Queue::fake([IndexRagSourceJob::class]);
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $attempts = 0;
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('Redis unavailable');
            }
            return 'delivered';
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $coordinator = new RagIndexingCoordinator(app(RagIndexer::class), new RagJobDispatcher($bus, $logger));

        DB::beginTransaction();
        $run = $coordinator->queueEntity($organization->id, null, 'estimate', 'estimate_item', 4207);
        $this->assertSame(0, $attempts);
        $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        DB::commit();

        $this->assertSame(1, $attempts);
        $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame(\RuntimeException::class, $run->fresh()->last_error);
        $this->travel(2)->minutes();
        $this->assertSame(1, $coordinator->recoverExpiredRuns());
        $this->assertSame(2, $attempts);
        $this->assertSame(1, RagIndexRun::query()->where('organization_id', $organization->id)->count());
        $this->assertNull($run->fresh()->last_error);
    }

    public function test_public_template_records_global_revision_before_commit_and_rolls_back_without_dispatch(): void
    {
        Queue::fake();
        $jobs = [];
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexGlobalRagEntityJob $job) use (&$jobs): string {
            $jobs[] = $job;
            return 'delivered';
        });
        $global = new GlobalRagQueue(new RagSourceRegistry([new EstimateReferenceRagSource()]), $bus, $this->createMock(LoggerInterface::class));
        $this->app->instance(GlobalRagQueue::class, $global);
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $user = User::withoutEvents(fn (): User => User::factory()->create());
        $template = EstimateTemplate::withoutEvents(fn (): EstimateTemplate => EstimateTemplate::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Публичный шаблон',
            'template_structure' => [],
            'is_public' => true,
            'created_by_user_id' => $user->id,
        ]));

        DB::beginTransaction();
        $template->update(['name' => 'Версия 2']);
        $event = RagGlobalIndexEvent::query()->where('source_type', 'estimate_reference')
            ->where('entity_type', 'estimate_template')->where('entity_id', (string) $template->id)->firstOrFail();
        $template->update(['name' => 'Версия 3']);
        $event->refresh();
        $this->assertSame(2, $event->revision);
        $this->assertSame([], $jobs);
        DB::rollBack();

        $this->assertDatabaseMissing('ai_rag_global_index_events', ['id' => $event->id]);
        $this->assertSame([], $jobs);
        $this->assertSame('Публичный шаблон', $template->fresh()->name);

        DB::beginTransaction();
        $template->update(['name' => 'Версия 2']);
        $latestEvent = RagGlobalIndexEvent::query()->where('source_type', 'estimate_reference')
            ->where('entity_type', 'estimate_template')->where('entity_id', (string) $template->id)->firstOrFail();
        $template->update(['name' => 'Версия 3']);
        $this->assertSame(2, $latestEvent->fresh()->revision);
        $this->assertSame([], $jobs);
        DB::commit();

        $this->assertCount(1, $jobs);
        $this->assertSame($latestEvent->id, $jobs[0]->eventId);
        $this->assertSame(2, $jobs[0]->eventRevision);
        $this->assertSame('Версия 3', $template->fresh()->name);
        $template->update(['is_public' => false]);
        $this->assertSame(3, $latestEvent->fresh()->revision);
        $this->assertCount(2, $jobs);
        $this->assertNotNull($global->claim($latestEvent->id, 3));
        $template->update(['is_public' => true]);
        $template->delete();
        $this->assertSame(5, $latestEvent->fresh()->revision);
        $this->assertSame(5, $jobs[array_key_last($jobs)]->eventRevision);
        $this->assertNull($global->claim($latestEvent->id, 3));
    }

    public function test_global_event_insert_failure_rolls_back_public_template_mutation(): void
    {
        $bus = $this->createMock(Dispatcher::class);
        $bus->expects($this->never())->method('dispatch');
        $global = new GlobalRagQueue(new RagSourceRegistry([new EstimateReferenceRagSource()]), $bus,
            $this->createMock(LoggerInterface::class));
        $this->app->instance(GlobalRagQueue::class, $global);
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $user = User::withoutEvents(fn (): User => User::factory()->create());
        $template = EstimateTemplate::withoutEvents(fn (): EstimateTemplate => EstimateTemplate::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Исходный публичный шаблон',
            'template_structure' => [],
            'is_public' => true,
            'created_by_user_id' => $user->id,
        ]));
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_contains(strtolower($query->sql), 'insert into "ai_rag_global_index_events"')) {
                return;
            }
            $injected = true;
            DB::select('SELECT 1 / 0');
        });

        try {
            DB::transaction(fn () => $template->update(['name' => 'Мутация с ошибкой']));
            $this->fail('Ожидалось исключение при записи global RAG intent');
        } catch (QueryException $exception) {
            $this->assertSame('22012', $exception->errorInfo[0] ?? null);
        }

        $this->assertTrue($injected);
        $this->assertSame('Исходный публичный шаблон', $template->fresh()->name);
        $this->assertDatabaseMissing('ai_rag_global_index_events', [
            'source_type' => 'estimate_reference',
            'entity_type' => 'estimate_template',
            'entity_id' => (string) $template->id,
        ]);
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
    }

    public function test_document_leaving_ready_prunes_only_its_source_and_rollback_restores_it(): void
    {
        Queue::fake([IndexRagSourceJob::class]);
        [$organization, $project] = $this->projectFixture();
        $otherOrganization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $user = User::withoutEvents(fn (): User => User::factory()->create());
        $file = File::withoutEvents(fn (): File => File::query()->create([
            'organization_id' => $organization->id,
            'fileable_type' => Project::class,
            'fileable_id' => $project->id,
            'user_id' => $user->id,
            'name' => 'rag-test.txt',
            'original_name' => 'rag-test.txt',
            'path' => 'org-'.$organization->id.'/rag-test.txt',
            'mime_type' => 'text/plain',
            'size' => 21,
            'disk' => 's3',
            'type' => 'document',
        ]));
        $document = AIAssistantDocument::withoutEvents(fn (): AIAssistantDocument => AIAssistantDocument::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'file_id' => $file->id,
            'parent_entity_type' => 'project',
            'parent_entity_id' => (string) $project->id,
            'storage_path' => 'org-'.$organization->id.'/rag-test.txt',
            'filename' => 'rag-test.txt',
            'mime_type' => 'text/plain',
            'checksum' => hash('sha256', 'rag durability'),
            'size_bytes' => 14,
            'status' => AIAssistantDocument::STATUS_READY,
            'extracted_text' => 'RAG durability source',
        ]));
        $target = $this->source($organization->id, 'file_document', 'assistant_document', $document->id);
        $sameEntityOtherOrganization = $this->source($otherOrganization->id, 'file_document', 'assistant_document', $document->id);
        $sameOrganizationOtherSource = $this->source($organization->id, 'estimate', 'assistant_document', $document->id);

        DB::beginTransaction();
        $document->update(['status' => AIAssistantDocument::STATUS_FAILED]);
        $this->assertDatabaseMissing('ai_rag_sources', ['id' => $target->id]);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $sameEntityOtherOrganization->id]);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $sameOrganizationOtherSource->id]);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();

        $this->assertSame(AIAssistantDocument::STATUS_READY, $document->fresh()->status);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $target->id]);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $sameEntityOtherOrganization->id]);
        $this->assertDatabaseHas('ai_rag_sources', ['id' => $sameOrganizationOtherSource->id]);
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_s3_file_mutation_records_file_document_run_inside_transaction(): void
    {
        Queue::fake([IndexRagSourceJob::class]);
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $project = Project::withoutEvents(fn (): Project => Project::factory()->create(['organization_id' => $organization->id]));
        $user = User::withoutEvents(fn (): User => User::factory()->create());

        DB::beginTransaction();
        $file = File::query()->create([
            'organization_id' => $organization->id,
            'fileable_type' => Project::class,
            'fileable_id' => $project->id,
            'user_id' => $user->id,
            'name' => 'rag-file.txt',
            'original_name' => 'rag-file.txt',
            'path' => 'org-'.$organization->id.'/rag-file.txt',
            'mime_type' => 'text/plain',
            'size' => 14,
            'disk' => 's3',
            'type' => 'document',
            'category' => 'assistant',
        ]);

        $run = RagIndexRun::query()->where('organization_id', $organization->id)
            ->where('source_type', 'file_document')->where('entity_type', 'file')
            ->where('entity_id', (string) $file->id)->firstOrFail();
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();

        $this->assertDatabaseMissing('ai_rag_index_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    private function projectFixture(): array
    {
        $organization = Organization::withoutEvents(fn (): Organization => Organization::factory()->create());
        $project = Project::withoutEvents(fn (): Project => Project::factory()->create(['organization_id' => $organization->id]));

        return [$organization, $project];
    }

    private function source(int $organizationId, string $sourceType, string $entityType, int $entityId): RagSource
    {
        return RagSource::query()->create([
            'organization_id' => $organizationId,
            'source_type' => $sourceType,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'title' => 'Источник '.$entityId,
            'checksum' => hash('sha256', $organizationId.'-'.$sourceType.'-'.$entityId),
            'metadata' => [],
        ]);
    }
}

final class RagProducerSqlFailureCoordinator extends RagIndexingCoordinator
{
    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        DB::transaction(static fn () => DB::select('SELECT 1 / 0'));

        return parent::queueEntity($organizationId, $projectId, $sourceType, $entityType, $entityId);
    }
}
