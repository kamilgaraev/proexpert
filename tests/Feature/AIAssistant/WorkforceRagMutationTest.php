<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkforcePayrollRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\WorkforceRagSource;
use App\BusinessModules\Features\WorkforceManagement\Services\PayrollCalculationVersionService;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class WorkforceRagMutationTest extends TestCase
{
    public function test_native_file_dispatch_outage_preserves_real_document_and_pending_source_for_recovery(): void
    {
        Queue::fake();
        $fixture = \Tests\Support\AssistantRealAuthorizationFixture::create(['working-entry', 'workforce-output']);
        $content = "employee,hours,amount\nИван,8,1200.50\n";
        $storage = \Mockery::mock(\App\Services\Storage\FileService::class);
        $storage->shouldReceive('readCurrentBounded')->andReturnUsing(static function () use ($content): mixed {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $content);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(\App\Services\Storage\FileService::class, $storage);
        $org = $fixture->organization->id;
        $period = DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $org, 'project_id' => null,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now()]);
        $key = '20260929140000-0123456789abcdef';
        $package = DB::table('workforce_export_packages')->insertGetId(['organization_id' => $org, 'payroll_period_id' => $period,
            'package_number' => 'WF-'.$period.'-'.$key, 'source_hash' => hash('sha256', 'payroll-source'), 'status' => 'created',
            'created_by_user_id' => $fixture->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $nativeId = DB::table('workforce_export_package_files')->insertGetId(['organization_id' => $org, 'export_package_id' => $package,
            'file_type' => 'source_csv', 'file_name' => 'payroll-source.csv', 'storage_disk' => 's3',
            'storage_path' => 'org-'.$org.'/workforce/payroll-exports/period-'.$period.'/package-'.$key.'/payroll-source.csv',
            'size_bytes' => strlen($content), 'created_at' => now(), 'updated_at' => now()]);
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        DB::beginTransaction();
        app(\App\BusinessModules\Features\AIAssistant\Services\Rag\WorkforceRagMutationBridge::class)
            ->changed('workforce_export_package_files', $org, (int) $nativeId);
        DB::commit();
        $run = RagIndexRun::query()->where('organization_id', $org)->where('entity_type', 'workforce_export_package_file')
            ->where('entity_id', (string) $nativeId)->firstOrFail();
        $originalBus = app(Dispatcher::class);
        \Illuminate\Support\Facades\Bus::partialMock()->shouldReceive('dispatch')
            ->with(\Mockery::type(\App\Jobs\ProcessAssistantDocument::class))->once()->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            (new IndexRagSourceJob($org, null, 'workforce_payroll', $run->id, 'workforce_export_package_file', (int) $nativeId))->handle($indexer, $coordinator);
            $this->fail('Extraction dispatch must fail without losing the durable source.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        } finally {
            \Illuminate\Support\Facades\Bus::swap($originalBus);
        }
        Queue::fake();
        $this->assertSame(RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame(\RuntimeException::class, $run->fresh()->last_error);
        $document = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::query()
            ->where('organization_id', $org)->where('parent_entity_type', 'workforce_export_package_file')->where('parent_entity_id', (string) $nativeId)->firstOrFail();
        $this->assertSame(hash('sha256', $content), $document->checksum);
        $this->assertSame('queued', $document->status);
        $this->travel(2)->minutes();
        $queuedBeforeRecovery = count($jobs->items);
        $this->assertGreaterThanOrEqual(1, $coordinator->recoverExpiredRuns());
        $recoveredJobs = array_values(array_filter(
            array_slice($jobs->items, $queuedBeforeRecovery),
            static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id,
        ));
        $this->assertCount(1, $recoveredJobs);
        $this->assertSame($run->id, $recoveredJobs[0]->runId);
        $this->assertSame('workforce_export_package_file', $recoveredJobs[0]->entityType);
        $this->assertSame((string) $nativeId, (string) $recoveredJobs[0]->entityId);
        $recoveredJobs[0]->handle($indexer, $coordinator);
        $this->assertSame(RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        $this->assertSame($document->id, $document->fresh()->id);
        $this->assertSame(hash('sha256', $content), $document->fresh()->checksum);
        $this->assertSame(1, \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::query()
            ->where('organization_id', $org)->where('parent_entity_type', 'workforce_export_package_file')->where('parent_entity_id', (string) $nativeId)->count());
        Queue::assertPushed(\App\Jobs\ProcessAssistantDocument::class, fn ($job): bool => $job->documentId === $document->id);
        Queue::assertPushed(\App\Jobs\ProcessAssistantDocument::class, 1);
    }

    public function test_raw_brigade_specialization_sync_persists_intents_and_dispatches_only_after_commit(): void
    {
        Queue::fake();
        $org = Organization::withoutEvents(fn () => Organization::factory()->create());
        $user = User::withoutEvents(fn () => User::factory()->create());
        $brigade = \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile::withoutEvents(fn () =>
            \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile::query()->create([
                'organization_id' => $org->id, 'owner_user_id' => $user->id, 'name' => 'Монтажная бригада',
                'slug' => 'rag-montazh', 'contact_person' => 'Иван', 'contact_phone' => '+70000000000',
                'contact_email' => 'rag-brigade@example.test', 'verification_status' => 'approved',
            ]));
        $old = \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeSpecialization::withoutEvents(fn () =>
            \App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeSpecialization::query()->create(['name' => 'Старый монтаж', 'slug' => 'rag-old-specialization']));
        $brigade->specializations()->attach($old->id);
        $oldPivot = (int) DB::table('brigade_profile_specialization')->where('brigade_id', $brigade->id)->value('id');
        $service = app(\App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService::class);
        DB::beginTransaction();
        $service->syncSpecializations($brigade, ['Новый монтаж']);
        $newSpecialization = (int) DB::table('brigade_specializations')->where('name', 'Новый монтаж')->value('id');
        $newPivot = (int) DB::table('brigade_profile_specialization')->where('brigade_id', $brigade->id)->value('id');
        $intentIdentities = \App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent::query()->where('source_type', 'brigades')
            ->get(['entity_type', 'entity_id'])->map(static fn ($event): string => $event->entity_type.':'.$event->entity_id)->all();
        $this->assertEqualsCanonicalizing([
            'brigade_specialization:'.$newSpecialization,
            'brigade_specialization_link:'.$oldPivot,
            'brigade_specialization_link:'.$newPivot,
            'brigade_profile:'.$brigade->id,
        ], $intentIdentities);
        Queue::assertNotPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob::class);
        DB::rollBack();
        $this->assertSame(1, DB::table('brigade_profile_specialization')->where('id', $oldPivot)->count());
        $this->assertSame(0, DB::table('brigade_profile_specialization')->where('id', $newPivot)->count());
        $this->assertSame(0, \App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent::query()->where('source_type', 'brigades')->count());
        Queue::assertNotPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob::class);
        DB::beginTransaction();
        $service->syncSpecializations($brigade, ['Новый монтаж']);
        $newPivot = (int) DB::table('brigade_profile_specialization')->where('brigade_id', $brigade->id)->value('id');
        $newSpecialization = (int) DB::table('brigade_specializations')->where('name', 'Новый монтаж')->value('id');
        $this->assertNotSame($oldPivot, $newPivot);
        Queue::assertNotPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob::class);
        DB::commit();
        $events = \App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent::query()->where('source_type', 'brigades')
            ->where('entity_type', 'brigade_specialization_link')->pluck('entity_id')->all();
        $this->assertEqualsCanonicalizing([(string) $oldPivot, (string) $newPivot], $events);
        $this->assertSame(0, DB::table('brigade_profile_specialization')->where('id', $oldPivot)->count());
        $this->assertSame(1, \App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent::query()
            ->where('source_type', 'brigades')->where('entity_type', 'brigade_profile')->where('entity_id', (string) $brigade->id)->count());
        $this->assertSame(1, \App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent::query()
            ->where('source_type', 'brigades')->where('entity_type', 'brigade_specialization')->where('entity_id', (string) $newSpecialization)->count());
        Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob::class, 4);
        foreach ([
            ['brigade_specialization_link', $oldPivot],
            ['brigade_specialization_link', $newPivot],
            ['brigade_profile', $brigade->id],
            ['brigade_specialization', $newSpecialization],
        ] as [$entityType, $entityId]) {
            Queue::assertPushed(\App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob::class,
                static fn ($job): bool => $job->sourceType === 'brigades' && $job->entityType === $entityType && (string) $job->entityId === (string) $entityId);
        }
    }

    public function test_actual_query_builder_assignment_update_dispatches_after_commit_and_rollback_preserves_source(): void
    {
        $fixture = $this->fixture();
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $indexer->indexEntity($fixture['org'], 'workforce', 'workforce_employee_assignment', $fixture['assignment']);
        $source = $this->source($fixture['org'], 'workforce_employee_assignment', $fixture['assignment']);
        $checksum = $source->checksum;

        DB::beginTransaction();
        app(WorkforceProService::class)->update('workforce_employee_assignments', $fixture['org'], $fixture['assignment'], ['status' => 'cancelled']);
        $this->assertSame([], $jobs->items);
        $this->assertSame(1, RagIndexRun::query()->where('entity_type', 'workforce_employee_assignment')->where('entity_id', (string) $fixture['assignment'])->count());
        DB::rollBack();
        $this->assertSame(0, RagIndexRun::query()->where('organization_id', $fixture['org'])->count());
        $this->assertSame('active', DB::table('workforce_employee_assignments')->where('id', $fixture['assignment'])->value('status'));
        $this->assertSame($checksum, $source->fresh()->checksum);

        DB::beginTransaction();
        app(WorkforceProService::class)->update('workforce_employee_assignments', $fixture['org'], $fixture['assignment'], ['status' => 'cancelled']);
        $this->assertSame([], $jobs->items);
        DB::commit();
        $this->assertNotEmpty($jobs->items);
        foreach ($jobs->items as $job) {
            $job->handle($indexer, $coordinator);
        }
        $this->assertNotSame($checksum, $source->fresh()->checksum);
        $this->assertStringContainsString('cancelled', $source->fresh()->chunks()->firstOrFail()->content);
    }

    public function test_real_immutable_payroll_build_queues_version_rows_and_transition_atomically_and_reuses_pending_runs(): void
    {
        $fixture = $this->fixture();
        [, , $jobs] = $this->pipeline();
        $service = app(PayrollCalculationVersionService::class);
        DB::beginTransaction();
        $rolledBack = $service->build($fixture['org'], $fixture['period'], $fixture['user']);
        $this->assertSame([], $jobs->items);
        $this->assertSame(1, RagIndexRun::query()->where('entity_type', 'workforce_payroll_calculation_version')->where('entity_id', (string) $rolledBack->id)->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('workforce_payroll_calculation_versions')->where('organization_id', $fixture['org'])->count());
        $this->assertSame(0, RagIndexRun::query()->where('organization_id', $fixture['org'])->count());

        DB::beginTransaction();
        $version = $service->build($fixture['org'], $fixture['period'], $fixture['user']);
        $pending = RagIndexRun::query()->where('organization_id', $fixture['org'])->count();
        $same = $service->build($fixture['org'], $fixture['period'], $fixture['user']);
        $this->assertSame($version->id, $same->id);
        $this->assertSame($pending, RagIndexRun::query()->where('organization_id', $fixture['org'])->count());
        $this->assertSame([], $jobs->items);
        $this->assertSame(1, RagIndexRun::query()->where('organization_id', $fixture['org'])->where('entity_type', 'workforce_payroll_calculation_source_row')->count());
        $this->assertSame(1, RagIndexRun::query()->where('organization_id', $fixture['org'])->where('entity_type', 'workforce_payroll_calculation_transition')->count());
        DB::commit();
        $this->assertNotEmpty($jobs->items);
        $this->assertSame(1, DB::table('workforce_payroll_calculation_versions')->where('organization_id', $fixture['org'])->count());
    }

    public function test_source_rebuild_removes_deleted_query_builder_rows_from_actual_index(): void
    {
        $fixture = $this->fixture();
        [$indexer, $coordinator, $jobs] = $this->pipeline();
        $indexer->indexEntity($fixture['org'], 'workforce_payroll', 'workforce_payroll_source_row', $fixture['source']);
        $source = $this->source($fixture['org'], 'workforce_payroll_source_row', $fixture['source']);
        $this->assertGreaterThan(0, $source->chunks()->count());
        DB::beginTransaction();
        app(WorkforceProService::class)->buildPayrollSource($fixture['org'], $fixture['period']);
        $this->assertSame([], $jobs->items);
        $this->assertSame(0, DB::table('workforce_payroll_source_rows')->where('id', $fixture['source'])->count());
        DB::commit();
        foreach ($jobs->items as $job) {
            $job->handle($indexer, $coordinator);
        }
        $this->assertSame(0, RagSource::query()->where('organization_id', $fixture['org'])->where('entity_type', 'workforce_payroll_source_row')->where('entity_id', (string) $fixture['source'])->count());
        $this->assertSame(0, DB::table('ai_rag_chunks')->where('source_id', $source->id)->count());
    }

    public function test_native_payroll_guard_allows_unlocked_source_update_and_rejects_locked_source_update(): void
    {
        $fixture = $this->fixture();
        $this->assertSame(1, DB::table('workforce_payroll_source_rows')->where('id', $fixture['source'])
            ->update(['amount' => '101.0000']));
        $this->assertSame('101.00', (string) DB::table('workforce_payroll_source_rows')->where('id', $fixture['source'])
            ->value('amount'));

        DB::table('workforce_payroll_periods')->where('id', $fixture['period'])->update(['status' => 'locked']);
        DB::beginTransaction();
        try {
            DB::table('workforce_payroll_source_rows')->where('id', $fixture['source'])->update(['amount' => '102.0000']);
            $this->fail('Locked payroll source must remain immutable.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0] ?? null);
            $this->assertStringContainsString('immutable locked payroll source', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame('101.00', (string) DB::table('workforce_payroll_source_rows')->where('id', $fixture['source'])
            ->value('amount'));
    }

    private function pipeline(): array
    {
        $jobs = new class { public array $items = []; };
        $bus = $this->createMock(Dispatcher::class);
        $bus->method('dispatch')->willReturnCallback(static function (IndexRagSourceJob $job) use ($jobs): null {
            $jobs->items[] = $job;
            return null;
        });
        $embedding = $this->createMock(RagEmbeddingProviderInterface::class);
        $embedding->method('embed')->willReturn(RagTestEmbedding::fromLeadingValues([1.0]));
        $embedding->method('provider')->willReturn('test');
        $embedding->method('model')->willReturn('workforce-lifecycle');
        $embedding->method('dimensions')->willReturn(RagTestEmbedding::DIMENSIONS);
        $indexer = new RagIndexer($embedding, new RagSourceRegistry([new WorkforceRagSource(), new WorkforcePayrollRagSource()]));
        $coordinator = new RagIndexingCoordinator($indexer, new RagJobDispatcher($bus, new NullLogger()));
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        return [$indexer, $coordinator, $jobs];
    }

    private function source(int $organizationId, string $type, int $id): RagSource
    {
        return RagSource::query()->where('organization_id', $organizationId)->where('entity_type', $type)->where('entity_id', (string) $id)->firstOrFail();
    }

    private function fixture(): array
    {
        Queue::fake();
        $org = Organization::withoutEvents(fn () => Organization::factory()->create());
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $org->id]));
        $user = User::withoutEvents(fn () => User::factory()->create());
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'is_owner' => true, 'is_active' => true] + $timestamps);
        $department = DB::table('workforce_departments')->insertGetId(['organization_id' => $org->id, 'code' => 'RAG-DEPT', 'name' => 'Отдел', 'is_active' => true] + $timestamps);
        $position = DB::table('workforce_positions')->insertGetId(['organization_id' => $org->id, 'code' => 'RAG-POS', 'name' => 'Рабочий', 'is_active' => true] + $timestamps);
        $staff = DB::table('workforce_staff_units')->insertGetId(['organization_id' => $org->id, 'department_id' => $department, 'position_id' => $position, 'code' => 'RAG-STAFF', 'headcount' => '1.00', 'rate' => '1.0000', 'valid_from' => '2026-01-01', 'is_active' => true] + $timestamps);
        $employee = DB::table('workforce_employees')->insertGetId(['organization_id' => $org->id, 'personnel_number' => 'RAG-EMP', 'last_name' => 'Иванов', 'first_name' => 'Иван', 'employment_status' => 'active', 'hire_date' => '2026-01-01'] + $timestamps);
        $assignment = DB::table('workforce_employee_assignments')->insertGetId(['organization_id' => $org->id, 'employee_id' => $employee, 'staff_unit_id' => $staff, 'department_id' => $department, 'position_id' => $position, 'project_id' => $project->id, 'rate' => '1.0000', 'valid_from' => '2026-01-01', 'status' => 'active'] + $timestamps);
        $period = DB::table('workforce_payroll_periods')->insertGetId(['organization_id' => $org->id, 'project_id' => $project->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'draft', 'created_by_user_id' => $user->id] + $timestamps);
        $source = DB::table('workforce_payroll_source_rows')->insertGetId(['organization_id' => $org->id, 'payroll_period_id' => $period, 'employee_id' => $employee, 'project_id' => $project->id, 'work_date' => '2026-07-10', 'source_type' => 'timesheet_hours', 'hours' => '8.0000', 'amount' => '100.0000', 'payload' => '{}'] + $timestamps);
        return ['org' => (int) $org->id, 'project' => (int) $project->id, 'user' => (int) $user->id, 'assignment' => (int) $assignment, 'period' => (int) $period, 'source' => (int) $source];
    }
}
