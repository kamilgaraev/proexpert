<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterReadinessService;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagJobDispatcher;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class AssistantCoreRagMutationTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        self::assertSame(['ready' => true, 'phase' => 'phase_b', 'reason' => null],
            app(ImmutableAuditWriterReadinessService::class)->status(DB::connection(), (string) config('legal_archive.audit_writer_secret')));
    }

    public function test_schedule_replacement_queues_deleted_and_new_identities_only_after_commit(): void
    {
        [$fixture, $document, $old, $unrelated] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        DB::beginTransaction();
        self::assertSame(['ready' => true, 'phase' => 'phase_b', 'reason' => null],
            app(ImmutableAuditWriterReadinessService::class)->status(DB::connection(), (string) config('legal_archive.audit_writer_secret')));
        $replacement = app(PaymentScheduleService::class)->replacePendingSchedule($document, $this->installments(), $fixture->owner);
        Queue::assertNotPushed(IndexRagSourceJob::class);
        self::assertFalse(PaymentSchedule::query()->whereKey($old->id)->exists());
        self::assertTrue(PaymentSchedule::query()->whereKey($unrelated->id)->exists());
        DB::commit();
        $this->assertDatabaseHas('immutable_audit_events', ['organization_id' => $fixture->organization->id,
            'subject_id' => (string) $document->id, 'event_type' => 'payments.rescheduled']);
        $expected = array_map('strval', [$old->id, ...array_column($replacement, 'id')]);
        $runs = $this->scheduleRuns($fixture->organization->id);
        self::assertEqualsCanonicalizing($expected, $runs->pluck('entity_id')->all());
        self::assertSame(count($expected), $runs->count());
        self::assertSame([$document->project_id], $runs->pluck('project_id')->unique()->all());
        self::assertSame('2100.00', number_format((float) PaymentSchedule::query()->where('payment_document_id', $document->id)->sum('amount'), 2, '.', ''));
        foreach ($runs->get() as $run) {
            Queue::assertPushed(IndexRagSourceJob::class, static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id);
        }
    }

    public function test_business_rollback_restores_original_schedule_and_discards_durable_runs_and_jobs(): void
    {
        [$fixture, $document, $old, $unrelated] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        $beforeRuns = RagIndexRun::query()->where('organization_id', $fixture->organization->id)->count();
        DB::beginTransaction();
        $replacement = app(PaymentScheduleService::class)->replacePendingSchedule($document, $this->installments(), $fixture->owner);
        self::assertFalse(PaymentSchedule::query()->whereKey($old->id)->exists());
        Queue::assertNotPushed(IndexRagSourceJob::class);
        DB::rollBack();
        $this->assertDatabaseMissing('immutable_audit_events', ['organization_id' => $fixture->organization->id,
            'subject_id' => (string) $document->id, 'event_type' => 'payments.rescheduled']);
        self::assertSame('2100.00', PaymentSchedule::query()->findOrFail($old->id)->amount);
        self::assertSame(0, PaymentSchedule::query()->whereIn('id', array_column($replacement, 'id'))->count());
        self::assertTrue(PaymentSchedule::query()->whereKey($unrelated->id)->exists());
        self::assertSame(0, $this->scheduleRuns($fixture->organization->id)->count());
        self::assertSame($beforeRuns, RagIndexRun::query()->where('organization_id', $fixture->organization->id)->count());
        Queue::assertNotPushed(IndexRagSourceJob::class);
    }

    public function test_actual_postgres_queue_error_rolls_back_its_savepoint_and_business_change_remains_committable(): void
    {
        [$fixture, $document, $old] = $this->fixture();
        Queue::fake([IndexRagSourceJob::class]);
        $coordinator = new CoreMutationSqlFailureCoordinator(app(RagIndexer::class), app(RagJobDispatcher::class));
        $coordinator->failScheduleId = (string) $old->id;
        $this->app->instance(RagIndexingCoordinator::class, $coordinator);
        DB::beginTransaction();
        $replacement = app(PaymentScheduleService::class)->replacePendingSchedule($document, $this->installments(), $fixture->owner);
        self::assertGreaterThan(0, $coordinator->failures);
        self::assertSame('22012', $coordinator->sqlState);
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
        DB::commit();
        $this->assertDatabaseHas('immutable_audit_events', ['organization_id' => $fixture->organization->id,
            'subject_id' => (string) $document->id, 'event_type' => 'payments.rescheduled']);
        self::assertFalse(PaymentSchedule::query()->whereKey($old->id)->exists());
        self::assertSame(2, PaymentSchedule::query()->whereIn('id', array_column($replacement, 'id'))->count());
        self::assertSame(0, $this->scheduleRuns($fixture->organization->id)->where('entity_id', (string) $old->id)->count());
        self::assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
    }

    private function scheduleRuns(int $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        return RagIndexRun::query()->where('organization_id', $organizationId)->where('source_type', 'core_business_money')->where('entity_type', 'core_payment_schedule');
    }

    private function installments(): array
    {
        return [['installment_number' => 1, 'due_date' => '2026-10-01', 'amount' => '1000.00'],
            ['installment_number' => 2, 'due_date' => '2026-10-15', 'amount' => '1100.00']];
    }

    private function fixture(): array
    {
        return Model::withoutEvents(function (): array {
            $fixture = AssistantRealAuthorizationFixture::create();
            $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $data = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id,
                'document_type' => PaymentDocumentType::PAYMENT_ORDER, 'document_date' => '2026-09-29', 'direction' => InvoiceDirection::OUTGOING,
                'amount' => '2100.00', 'paid_amount' => '0.00', 'remaining_amount' => '2100.00', 'currency' => 'RUB',
                'status' => PaymentDocumentStatus::SCHEDULED, 'due_date' => '2026-10-01', 'created_by_user_id' => $fixture->owner->id];
            $document = PaymentDocument::query()->create($data + ['document_number' => 'CORE-MUTATION-OWN']);
            $unrelatedDocument = PaymentDocument::query()->create($data + ['document_number' => 'CORE-MUTATION-OTHER']);
            $schedule = ['installment_number' => 1, 'due_date' => '2026-10-01', 'amount' => '2100.00', 'paid_amount' => '0.00', 'status' => 'pending'];
            $old = PaymentSchedule::query()->create($schedule + ['payment_document_id' => $document->id]);
            $unrelated = PaymentSchedule::query()->create($schedule + ['payment_document_id' => $unrelatedDocument->id]);
            return [$fixture, $document, $old, $unrelated];
        });
    }
}

final class CoreMutationSqlFailureCoordinator extends RagIndexingCoordinator
{
    public string $failScheduleId = '';
    public int $failures = 0;
    public ?string $sqlState = null;

    public function queueEntity(int $organizationId, ?int $projectId, string $sourceType, string $entityType, string|int $entityId): RagIndexRun
    {
        if ($entityType === 'core_payment_schedule' && (string) $entityId === $this->failScheduleId) {
            $this->failures++;
            try { DB::select('SELECT 1 / 0'); }
            catch (QueryException $exception) { $this->sqlState = $exception->errorInfo[0] ?? null; throw $exception; }
        }
        return parent::queueEntity($organizationId, $projectId, $sourceType, $entityType, $entityId);
    }
}
