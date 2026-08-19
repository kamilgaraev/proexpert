<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ConfirmEstimateGenerationDocumentCost;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DispatchDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentCostJournalReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentWireAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StopEstimateGenerationDocumentProcessing;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentActionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\CommercialQuotaService;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class DocumentProcessingControlPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function stop_before_claim_prevents_provider_ownership(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $fixture['document']->forceFill(['processing_control_status' => 'cancelled'])->save();

            $now = new DateTimeImmutable;
            $claim = app(DocumentProcessingUnitStore::class)->claim(
                (int) $fixture['unit']->id,
                $fixture['source_version'],
                $now,
                $now->modify('+180 seconds'),
                3,
            );

            self::assertSame(DocumentProcessingUnitClaimStatus::Stale, $claim->status);
            self::assertSame('superseded', $fixture['unit']->fresh()->status->value);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function repeated_operator_stop_is_idempotent_in_postgres(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->twice()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->times(3)->andReturn($this->reviewRequiredReadiness());
            $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
            $this->app->forgetInstance(DocumentUnitAggregateReconciler::class);
            $service = new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
                app(DocumentUnitAggregateReconciler::class),
            );

            $first = $service->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'stop-idempotency-key',
            );
            $second = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'stop-idempotency-key',
            );

            self::assertSame('accepted', $first->disposition);
            self::assertSame('replayed', $second->disposition);
            self::assertSame(1, DB::table('estimate_generation_audit_events')
                ->where('session_id', $fixture['session']->id)
                ->where('event_type', 'document_processing_stopped')
                ->count());
            self::assertSame('superseded', $fixture['unit']->fresh()->status->value);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function operator_stop_reconciles_a_twenty_two_page_partial_result_for_list_snapshot_and_detail(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('600.00000000');
            $this->expandDocumentToPages($fixture, 22);
            $store = app(DocumentProcessingUnitStore::class);
            $now = new DateTimeImmutable;
            foreach (EstimateGenerationProcessingUnit::query()
                ->where('document_id', $fixture['document']->id)
                ->orderBy('unit_index')
                ->limit(10)
                ->get() as $unit) {
                $claim = $store->claim(
                    (int) $unit->id,
                    $fixture['sourceVersion'],
                    $now,
                    $now->modify('+180 seconds'),
                    3,
                );
                self::assertTrue($claim->acquired());
                self::assertTrue($store->publish(
                    $claim,
                    new DocumentUnitOutput(
                        version: 'document-174-partial-v1',
                        text: 'Сохранённый результат страницы '.(int) $unit->unit_index,
                        confidence: 0.95,
                        normalizedPayload: [
                            'independent_observations' => [],
                            'limitations' => [],
                        ],
                        unitType: $unit->unit_type,
                        unitIndex: (int) $unit->unit_index,
                        sourceVersion: $fixture['sourceVersion'],
                    ),
                    $now,
                ));
            }
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, (string) Str::uuid(), '17.15026500'),
            );
            $fixture['document']->fresh()->forceFill([
                'units_finalized_source_version' => $fixture['sourceVersion'],
                'units_reconciled_source_version' => $fixture['sourceVersion'],
                'units_reconcile_claim_token' => (string) Str::uuid(),
                'units_reconcile_lease_expires_at' => now()->addMinutes(5),
            ])->save();
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->twice()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->times(3)->andReturn($this->reviewRequiredReadiness());
            $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
            $this->app->forgetInstance(DocumentUnitAggregateReconciler::class);

            $stop = new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
                app(DocumentUnitAggregateReconciler::class),
            );
            $first = $stop->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'document-174-stop',
            );
            $fixture['document']->fresh()->forceFill([
                'status' => 'processing',
                'processing_stage' => 'quality_check',
                'units_finalized_source_version' => $fixture['sourceVersion'],
                'units_reconciled_source_version' => $fixture['sourceVersion'],
                'units_reconcile_claim_token' => (string) Str::uuid(),
                'units_reconcile_lease_expires_at' => now()->addMinutes(5),
            ])->save();
            $second = $stop->handle(
                $fixture['session']->fresh(),
                $fixture['document']->fresh(),
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'document-174-stop',
            );

            $document = $fixture['document']->fresh([
                'pages',
                'processingUnits',
                'facts',
                'drawingElements',
                'quantityTakeoffs',
                'scopeInferences',
            ]);
            $list = (new EstimateGenerationDocumentResource($document))->resolve();
            $detail = (new EstimateGenerationDocumentDetailResource($document))->resolve();
            $session = $fixture['session']->fresh();

            self::assertSame('needs_review', $document->status);
            self::assertSame('completed', $document->processing_stage);
            self::assertSame(100, $document->progress_percent);
            self::assertSame('accepted', $first->disposition);
            self::assertSame('replayed', $second->disposition);
            self::assertSame(10, $document->processed_page_count);
            self::assertSame('cancelled', $document->processing_control_status);
            self::assertSame($fixture['sourceVersion'], $document->units_finalized_source_version);
            self::assertSame($fixture['sourceVersion'], $document->units_reconciled_source_version);
            self::assertNull($document->units_reconcile_claim_token);
            self::assertNull($document->units_reconcile_lease_expires_at);
            self::assertSame(10, $list['processing_outcome']['usefulness']['usable_pages']);
            self::assertSame(22, $list['processing_outcome']['execution']['completed_pages']);
            self::assertSame(100, $list['processing_outcome']['execution']['progress_percent']);
            self::assertArrayNotHasKey('cost_journal', $list);
            self::assertCount(22, $detail['pages']);
            self::assertArrayNotHasKey('ai_questions', $detail);
            self::assertSame('input_review_required', $session->status->value);
            self::assertSame(35, $session->processing_progress);
            self::assertSame('17.15026500', (string) DB::table('estimate_generation_ai_usage')
                ->where('session_id', $session->id)
                ->sum('cost_amount'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function explicit_retry_after_stop_creates_a_fresh_active_lineage(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            Queue::fake();
            $fixture = $this->fixture();
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->twice()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->times(3)->andReturn($this->reviewRequiredReadiness());
            $this->app->instance(AuthorizationService::class, $authorization);
            $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
            $this->app->forgetInstance(DocumentUnitAggregateReconciler::class);
            $stop = new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
                app(DocumentUnitAggregateReconciler::class),
            );
            $stop->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'stop-before-retry',
            );

            $retry = app(RetryEstimateGenerationDocument::class)->handle(
                $fixture['session']->fresh(),
                $fixture['document']->fresh(),
                $fixture['user'],
                (int) $fixture['session']->fresh()->state_version,
                $fixture['sourceVersion'],
                'retry-after-stop',
                'Продолжить после остановки',
            );

            $document = $fixture['document']->fresh();
            self::assertSame('accepted', $retry->disposition);
            self::assertSame('active', $document->processing_control_status);
            self::assertNotSame($fixture['lineage'], $document->processing_control_attempt_id);
            self::assertSame('pending', $fixture['unit']->fresh()->status->value);
            Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class, 1);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function cost_confirmation_uses_exact_decimal_arithmetic_and_is_idempotent(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            Queue::fake();
            config()->set('estimate-generation.generation.document_cost_limit_rub', '0.20000000');
            config()->set('estimate-generation.generation.document_cost_confirmation_increment_rub', '0.20000000');
            config()->set('estimate-generation.generation.session_cost_confirmation_increment_rub', '0.30000000');
            $fixture = $this->fixture('0.10000000');
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $fixture['document']->forceFill([
                'status' => 'needs_review',
                'processing_stage' => 'quality_check',
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'cost_limit_reached',
                'meta' => [
                    ...$fixture['document']->meta,
                    'processing_cost_guard' => ['version' => 2],
                ],
            ])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->times(6)->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->times(6)->andReturn(['summary' => []]);
            $service = new ConfirmEstimateGenerationDocumentCost(
                $authorization,
                $readiness,
                app(DispatchDocumentProcessingUnits::class),
                app(DocumentMutationSessionReconciler::class),
            );

            $first = $service->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'cost-confirmation-key',
            );
            $second = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'cost-confirmation-key',
            );
            $fixture['document']->fresh()->forceFill([
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'cost_limit_reached',
            ])->save();
            $third = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'cost-confirmation-key-b',
            );
            $fixture['document']->fresh()->forceFill([
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'cost_limit_reached',
            ])->save();
            $delayedFirst = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'cost-confirmation-key',
            );
            $fixture['document']->fresh()->forceFill([
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'session_cost_limit_reached',
            ])->save();
            $sessionConfirmation = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'session-cost-confirmation-key',
            );
            $document = $fixture['document']->fresh();
            $document->forceFill([
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'session_cost_limit_reached',
                'meta' => [
                    ...$document->meta,
                    'session_cost_guard_confirmation_version' => 0,
                ],
            ])->save();
            $sameSessionConfirmation = $service->handle(
                $fixture['session']->fresh(),
                $document,
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'session-cost-confirmation-key-b',
            );

            self::assertSame('accepted', $first->disposition);
            self::assertSame('replayed', $second->disposition);
            self::assertSame('accepted', $third->disposition);
            self::assertSame('replayed', $delayedFirst->disposition);
            self::assertSame('accepted', $sessionConfirmation->disposition);
            self::assertSame('accepted', $sameSessionConfirmation->disposition);
            self::assertSame('0.50000000', (string) $fixture['document']->fresh()->processing_cost_limit);
            self::assertSame(2, (int) $fixture['document']->fresh()->processing_cost_confirmation_version);
            self::assertSame(
                1,
                (int) data_get($fixture['session']->fresh()->analysis_payload, 'internal_cost_guard.confirmation_version'),
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function production_shaped_session_74_document_176_recovery_preserves_paid_page_results(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            Queue::fake();
            $fixture = $this->fixture('600.00000000');
            $this->expandDocumentToPages($fixture, 22);
            $unitStore = app(DocumentProcessingUnitStore::class);
            $now = new DateTimeImmutable;
            foreach (EstimateGenerationProcessingUnit::query()
                ->where('document_id', $fixture['document']->id)
                ->orderBy('unit_index')
                ->limit(2)
                ->get() as $unit) {
                $claim = $unitStore->claim(
                    (int) $unit->id,
                    $fixture['sourceVersion'],
                    $now,
                    $now->modify('+180 seconds'),
                    3,
                );
                self::assertTrue($claim->acquired());
                self::assertTrue($unitStore->publish(
                    $claim,
                    new DocumentUnitOutput(
                        version: 'production-document-176-page-v1',
                        text: 'Сохранённый результат страницы '.(int) $unit->unit_index,
                        confidence: 0.95,
                        normalizedPayload: ['independent_observations' => []],
                        unitType: $unit->unit_type,
                        unitIndex: (int) $unit->unit_index,
                        sourceVersion: $fixture['sourceVersion'],
                    ),
                    $now,
                ));
            }
            $pageThree = EstimateGenerationDocumentPage::query()
                ->where('document_id', $fixture['document']->id)
                ->where('page_number', 3)
                ->firstOrFail();
            $pageThreeFixture = [
                ...$fixture,
                'page' => $pageThree,
                'unit' => EstimateGenerationProcessingUnit::query()->findOrFail($pageThree->processing_unit_id),
            ];
            foreach ([
                'literal_observer' => '5.18346000',
                'construction_observer' => '3.61287000',
                'risk_observer' => '2.98147500',
            ] as $role => $cost) {
                [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($pageThreeFixture);
                $store->markWireStarted(
                    $context->attemptId,
                    $fingerprint,
                    $owner,
                    $now,
                    $now->modify('+180 seconds'),
                    new AiCost('11.05902000', 'RUB', 'available'),
                );
                $store->storeResponse(
                    $context->attemptId,
                    $fingerprint,
                    $owner,
                    ['parsed_envelope' => ['status' => 'ok']],
                    'succeeded',
                    200,
                    10,
                    'fixture-model',
                    ['pricing_status' => 'available', 'currency' => 'RUB'],
                );
                DB::table('estimate_generation_ai_usage')->insert(
                    $this->usageRow($pageThreeFixture, $context->attemptId, $cost),
                );
                $store->markUsageRecorded($context->attemptId, $fingerprint);
                $this->attachActiveRoleRun($pageThreeFixture, $context->attemptId, $owner);
                DB::table('estimate_generation_ai_role_runs')
                    ->where('physical_attempt_id', $context->attemptId)
                    ->update([
                        'role' => $role,
                        'status' => 'completed',
                        'result_payload' => json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
                        'owner_uuid' => null,
                        'lease_expires_at' => null,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            [$store, $arbiterContext, $arbiterFingerprint, $arbiterOwner] = $this->physicalAttempt($pageThreeFixture);
            $store->markWireStarted(
                $arbiterContext->attemptId,
                $arbiterFingerprint,
                $arbiterOwner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('11.05902000', 'RUB', 'available'),
            );
            $this->attachActiveRoleRun($pageThreeFixture, $arbiterContext->attemptId, $arbiterOwner);
            DB::table('estimate_generation_ai_role_runs')
                ->where('physical_attempt_id', $arbiterContext->attemptId)
                ->update(['role' => 'arbiter']);
            DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $arbiterContext->attemptId)
                ->update(['cost_reservation_amount' => null, 'cost_reservation_currency' => null]);
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $fixture['document']->forceFill([
                'status' => 'needs_review',
                'processing_stage' => 'quality_check',
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'cost_limit_reached',
                'processing_cost_confirmed_at' => null,
                'processing_cost_confirmation_version' => 0,
            ])->save();
            $fixture['session']->forceFill([
                'status' => 'input_review_required',
                'processing_stage' => 'input_review_required',
                'processing_progress' => 35,
            ])->save();
            DB::table('estimate_generation_ai_usage')->insert([
                $this->usageRow($fixture, (string) Str::uuid(), '3.00685500'),
                $this->usageRow($fixture, (string) Str::uuid(), '1.44072000'),
                $this->usageRow($fixture, (string) Str::uuid(), '4.57096500'),
            ]);
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->twice()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->once()->andReturn(['summary' => []]);
            $service = new ConfirmEstimateGenerationDocumentCost(
                $authorization,
                $readiness,
                app(DispatchDocumentProcessingUnits::class),
                app(DocumentMutationSessionReconciler::class),
            );
            $actions = (new EstimateGenerationDocumentActionBuilder($authorization))->forDocument(
                $fixture['document']->fresh()->load('session'),
                $fixture['user'],
            );
            self::assertSame('confirm_document_cost', $actions[0]['action'] ?? null);
            self::assertSame('Продолжить обработку', $actions[0]['label'] ?? null);
            self::assertSame('input_review_required', $fixture['session']->fresh()->status->value);

            $result = $service->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'production-document-176-resume',
            );

            $document = $fixture['document']->fresh();
            self::assertSame('accepted', $result->disposition);
            self::assertSame($fixture['lineage'], data_get($document->meta, 'processing_attempt_id'));
            self::assertSame('600.00000000', (string) $document->processing_cost_limit);
            self::assertSame(0, (int) $document->processing_cost_confirmation_version);
            self::assertNull($document->processing_cost_confirmed_at);
            self::assertSame('active', $document->processing_control_status);
            self::assertSame('processing_documents', $fixture['session']->fresh()->status->value);
            self::assertSame('20.79634500', (string) DB::table('estimate_generation_ai_usage')
                ->where('document_id', $document->id)->sum('cost_amount'));
            self::assertSame(3, DB::table('estimate_generation_ai_role_runs')
                ->where('page_id', $pageThree->id)->where('status', 'completed')->count());
            self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $arbiterContext->attemptId)->value('state'));
            self::assertSame(2, EstimateGenerationDocumentPage::query()
                ->where('document_id', $document->id)->where('status', 'ready')->count());
            self::assertSame(20, EstimateGenerationDocumentPage::query()
                ->where('document_id', $document->id)->where('status', 'queued')->count());
            Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 16);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function quota_reservations_reset_on_the_calendar_month_boundary(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            config()->set('commercial_limits.ai_estimates.included_monthly', 1);
            $fixture = $this->fixture();
            $commercialQuota = Mockery::mock(CommercialQuotaService::class);
            $commercialQuota->shouldReceive('getEffectiveAiEstimateMonthlyLimits')
                ->andReturn([$fixture['organization']->id => 1]);
            $quota = new AiEstimateQuotaService(DB::connection(), $commercialQuota);
            Carbon::setTestNow(Carbon::parse('2026-08-31 23:59:59', 'UTC'));
            $august = $quota->reserveSession(
                (string) $fixture['organization']->id,
                (string) $fixture['session']->id,
            );
            self::assertSame(1, $august->reserved);
            self::assertSame(0, $august->available);

            Carbon::setTestNow(Carbon::parse('2026-09-01 00:00:01', 'UTC'));
            $september = $quota->snapshot((string) $fixture['organization']->id);
            self::assertSame(0, $september->used);
            self::assertSame(0, $september->reserved);
            self::assertSame(1, $september->available);
        } finally {
            Carbon::setTestNow();
            DB::rollBack();
        }
    }

    #[Test]
    public function concurrent_quota_reservations_cannot_oversubscribe_the_monthly_limit(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $secondSession = EstimateGenerationSession::query()->create([
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'user_id' => $fixture['user']->id,
            'status' => 'processing_documents',
            'processing_stage' => 'understanding_documents',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $worker = dirname(__DIR__, 3).'/Support/AiEstimateQuotaConcurrentWorker.php';
        $environment = array_replace(getenv(), array_filter(
            $_ENV,
            static fn (mixed $value): bool => is_string($value),
        ));
        $definition = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $first = proc_open([
            PHP_BINARY, $worker, (string) $fixture['organization']->id, (string) $fixture['session']->id,
        ], $definition, $firstPipes, dirname(__DIR__, 4), $environment);
        $second = proc_open([
            PHP_BINARY, $worker, (string) $fixture['organization']->id, (string) $secondSession->id,
        ], $definition, $secondPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($first);
        self::assertIsResource($second);
        $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'READY');
        $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'READY');
        fwrite($firstPipes[0], "GO\n");
        fwrite($secondPipes[0], "GO\n");
        fclose($firstPipes[0]);
        fclose($secondPipes[0]);
        $firstOutput = $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'RESULT ');
        $secondOutput = $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'RESULT ');
        $firstError = stream_get_contents($firstPipes[2]);
        $secondError = stream_get_contents($secondPipes[2]);
        self::assertSame(0, proc_close($first), $firstError);
        self::assertSame(0, proc_close($second), $secondError);
        $results = [
            str_contains($firstOutput, 'RESULT reserved') ? 'reserved' : 'quota_exceeded',
            str_contains($secondOutput, 'RESULT reserved') ? 'reserved' : 'quota_exceeded',
        ];
        sort($results);
        self::assertSame(['quota_exceeded', 'reserved'], $results);
        self::assertSame(1, DB::table('estimate_generation_ai_estimate_quota_reservations')
            ->where('organization_id', $fixture['organization']->id)
            ->where('monthly_period', now()->startOfMonth()->toDateString())
            ->where('status', 'reserved')
            ->count());
    }

    #[Test]
    public function stop_between_due_scan_and_dispatch_prevents_enqueue(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $store = app(DocumentUnitDispatchStore::class);
            $candidate = $store->dueForDocument(
                (int) $fixture['document']->id,
                $fixture['source_version'],
                new DateTimeImmutable,
                1,
            )[0] ?? null;
            self::assertInstanceOf(DocumentUnitDispatchCandidate::class, $candidate);
            $fixture['document']->forceFill(['processing_control_status' => 'cancelled'])->save();
            $calls = 0;
            $now = new DateTimeImmutable;

            $dispatched = $store->dispatchIfAllowed(
                $candidate,
                $now,
                $now->modify('+300 seconds'),
                static function () use (&$calls): void {
                    $calls++;
                },
            );

            self::assertFalse($dispatched);
            self::assertSame(0, $calls);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function concurrent_stop_serializes_against_claim_and_dispatch(): void
    {
        $this->requirePostgres();
        foreach (['claim' => 'RESULT stale', 'dispatch' => 'RESULT blocked:not_invoked'] as $action => $expected) {
            $fixture = $this->fixture();
            $worker = dirname(__DIR__, 3).'/Support/DocumentProcessingControlConcurrentWorker.php';
            $command = [
                PHP_BINARY,
                $worker,
                '',
                (string) $fixture['document']->id,
                (string) $fixture['unit']->id,
                $fixture['sourceVersion'],
            ];
            $environment = array_replace(getenv(), array_filter(
                $_ENV,
                static fn (mixed $value): bool => is_string($value),
            ));
            $pipesDefinition = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $cancellerCommand = $command;
            $cancellerCommand[2] = 'cancel';
            $canceller = proc_open($cancellerCommand, $pipesDefinition, $cancellerPipes, dirname(__DIR__, 4), $environment);
            self::assertIsResource($canceller);
            $this->waitForProcessToken($canceller, $cancellerPipes[1], $cancellerPipes[2], 'LOCKED');

            $contenderCommand = $command;
            $contenderCommand[2] = $action;
            $contender = proc_open($contenderCommand, $pipesDefinition, $contenderPipes, dirname(__DIR__, 4), $environment);
            self::assertIsResource($contender);
            $this->waitForProcessToken($contender, $contenderPipes[1], $contenderPipes[2], 'READY');
            fwrite($contenderPipes[0], "GO\n");
            fclose($contenderPipes[0]);
            $this->waitForProcessToken($contender, $contenderPipes[1], $contenderPipes[2], 'ATTEMPT');
            fwrite($cancellerPipes[0], "GO\n");
            fclose($cancellerPipes[0]);

            $cancelOutput = $this->waitForProcessToken($canceller, $cancellerPipes[1], $cancellerPipes[2], 'RESULT ');
            $contenderOutput = $this->waitForProcessToken($contender, $contenderPipes[1], $contenderPipes[2], 'RESULT ');
            $cancelError = stream_get_contents($cancellerPipes[2]);
            $contenderError = stream_get_contents($contenderPipes[2]);
            self::assertSame(0, proc_close($canceller), $cancelError);
            self::assertSame(0, proc_close($contender), $contenderError);
            self::assertStringContainsString('RESULT cancelled', $cancelOutput);
            self::assertStringContainsString($expected, $contenderOutput);
        }
    }

    #[Test]
    public function three_sixty_four_page_documents_keep_recovery_bounded_and_isolate_stop_and_cost_pause(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            Queue::fake();
            config()->set('estimate-generation.vision.adaptive_analysis.max_in_flight_units_per_document', 16);
            $active = $this->fixture();
            $this->expandDocumentToPages($active, 64);
            $stopped = $this->siblingDocument($active, 'cancelled', null);
            $paused = $this->siblingDocument($active, 'paused', 'cost_limit_reached');
            $this->expandDocumentToPages($stopped, 64);
            $this->expandDocumentToPages($paused, 64);
            EstimateGenerationProcessingUnit::query()
                ->where('document_id', $stopped['document']->id)
                ->update(['status' => 'superseded']);
            self::assertSame(192, EstimateGenerationProcessingUnit::query()
                ->where('session_id', $active['session']->id)
                ->count());

            $memoryBefore = memory_get_usage(true);
            $dispatcher = app(DispatchDocumentProcessingUnits::class);
            $activeDispatched = $dispatcher->forDocument(
                (int) $active['document']->id,
                $active['sourceVersion'],
            );
            $stoppedDispatched = $dispatcher->forDocument(
                (int) $stopped['document']->id,
                $stopped['sourceVersion'],
            );
            $pausedDispatched = $dispatcher->forDocument(
                (int) $paused['document']->id,
                $paused['sourceVersion'],
            );
            $memoryGrowth = memory_get_usage(true) - $memoryBefore;

            self::assertSame(16, $activeDispatched);
            self::assertSame(0, $stoppedDispatched);
            self::assertSame(0, $pausedDispatched);
            Queue::assertPushed(ProcessEstimateGenerationUnitJob::class, 16);
            self::assertLessThanOrEqual(16 * 1024 * 1024, $memoryGrowth);
            self::assertSame(16, EstimateGenerationProcessingUnit::query()
                ->where('document_id', $active['document']->id)
                ->whereNotNull('next_dispatch_at')
                ->count());
            self::assertSame(0, EstimateGenerationProcessingUnit::query()
                ->whereIn('document_id', [$stopped['document']->id, $paused['document']->id])
                ->whereNotNull('next_dispatch_at')
                ->count());
            self::assertSame(64, EstimateGenerationProcessingUnit::query()
                ->where('document_id', $stopped['document']->id)
                ->where('status', 'superseded')
                ->count());
            self::assertSame(64, EstimateGenerationProcessingUnit::query()
                ->where('document_id', $paused['document']->id)
                ->where('status', 'pending')
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function cost_ceiling_pauses_before_the_next_wire(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('1.00000000');
            $now = new DateTimeImmutable;
            [$store, $paidContext, $paidFingerprint, $paidOwner] = $this->physicalAttempt($fixture);
            $store->markWireStarted(
                $paidContext->attemptId,
                $paidFingerprint,
                $paidOwner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('0.10000000', 'RUB', 'available'),
            );
            $store->storeResponse(
                $paidContext->attemptId,
                $paidFingerprint,
                $paidOwner,
                ['parsed_envelope' => ['status' => 'ok']],
                'succeeded',
                200,
                10,
                'fixture-model',
                ['pricing_status' => 'available', 'currency' => 'RUB'],
            );
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, $paidContext->attemptId, '1.00000000'),
            );
            $store->markUsageRecorded($paidContext->attemptId, $paidFingerprint);

            $unitStore = app(DocumentProcessingUnitStore::class);
            $claim = $unitStore->claim(
                (int) $fixture['unit']->id,
                $fixture['sourceVersion'],
                $now,
                $now->modify('+180 seconds'),
                3,
            );
            self::assertTrue($claim->acquired());
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);
            $this->attachActiveRoleRun($fixture, $context->attemptId, $owner);

            try {
                $store->markWireStarted(
                    $context->attemptId,
                    $fingerprint,
                    $owner,
                    $now,
                    $now->modify('+180 seconds'),
                    new AiCost('0.10000000', 'RUB', 'available'),
                );
                self::fail('Cost ceiling allowed another wire.');
            } catch (DocumentUnitProcessingException $exception) {
                self::assertSame('document_cost_limit_reached', $exception->safeCode);
            }

            $document = $fixture['document']->fresh();
            self::assertSame('paused', $document->processing_control_status);
            self::assertSame('cost_limit_reached', $document->processing_control_reason);
            app(DocumentCostJournalReader::class)->attach([$document]);
            self::assertSame('1.00000000', $document->getAttribute('processing_cost_spent_rub'));
            self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
            self::assertTrue($unitStore->pauseForCostConfirmation($claim, new DateTimeImmutable));
            self::assertSame('pending', $fixture['unit']->fresh()->status->value);
            self::assertSame(0, $fixture['unit']->fresh()->attempt_count);
            self::assertFalse(DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->exists());
            self::assertSame('failed', DB::table('estimate_generation_ai_role_runs')
                ->where('physical_attempt_id', null)
                ->where('failure_code', 'document_cost_limit_paused')
                ->value('status'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function spent_twenty_rubles_and_legacy_wire_attempt_do_not_exhaust_six_hundred_ruble_limit(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('600.00000000');
            $now = new DateTimeImmutable;
            [$store, $legacyContext, $legacyFingerprint, $legacyOwner] = $this->physicalAttempt($fixture);
            $store->markWireStarted(
                $legacyContext->attemptId,
                $legacyFingerprint,
                $legacyOwner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('11.05902000', 'RUB', 'available'),
            );
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, (string) Str::uuid(), '20.79634500'),
            );
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);

            $store->markWireStarted(
                $context->attemptId,
                $fingerprint,
                $owner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('11.05902000', 'RUB', 'available'),
            );

            self::assertSame('active', $fixture['document']->fresh()->processing_control_status);
            self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function next_physical_call_is_allowed_at_exact_document_limit_boundary(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('600.00000000');
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, (string) Str::uuid(), '588.94098000'),
            );
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);
            $now = new DateTimeImmutable;

            $store->markWireStarted(
                $context->attemptId,
                $fingerprint,
                $owner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('11.05902000', 'RUB', 'available'),
            );

            self::assertSame('wire_started', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function next_physical_call_that_can_exceed_document_limit_is_paused_before_wire(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('600.00000000');
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, (string) Str::uuid(), '588.94098001'),
            );
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);
            $now = new DateTimeImmutable;

            try {
                $store->markWireStarted(
                    $context->attemptId,
                    $fingerprint,
                    $owner,
                    $now,
                    $now->modify('+180 seconds'),
                    new AiCost('11.05902000', 'RUB', 'available'),
                );
                self::fail('Wire was started above the document limit.');
            } catch (DocumentUnitProcessingException $exception) {
                self::assertSame('document_cost_limit_reached', $exception->safeCode);
            }

            self::assertSame('paused', $fixture['document']->fresh()->processing_control_status);
            self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function parallel_cost_reservations_cannot_oversubscribe_document_limit(): void
    {
        $this->requirePostgres();
        config()->set('estimate-generation.generation.session_cost_limit_rub', '10.00000000');
        $fixture = $this->fixture('1.00000000');
        [, $firstContext, $firstFingerprint, $firstOwner] = $this->physicalAttempt($fixture);
        [, $secondContext, $secondFingerprint, $secondOwner] = $this->physicalAttempt($fixture);
        $worker = dirname(__DIR__, 3).'/Support/VisionCostReservationConcurrentWorker.php';
        $environment = array_replace(getenv(), array_filter(
            $_ENV,
            static fn (mixed $value): bool => is_string($value),
        ));
        $definition = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $first = proc_open([
            PHP_BINARY, $worker, $firstContext->attemptId, $firstFingerprint, $firstOwner, '0.60000000',
        ], $definition, $firstPipes, dirname(__DIR__, 4), $environment);
        $second = proc_open([
            PHP_BINARY, $worker, $secondContext->attemptId, $secondFingerprint, $secondOwner, '0.60000000',
        ], $definition, $secondPipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($first);
        self::assertIsResource($second);
        $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'READY');
        $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'READY');
        fwrite($firstPipes[0], "GO\n");
        fwrite($secondPipes[0], "GO\n");
        fclose($firstPipes[0]);
        fclose($secondPipes[0]);
        $firstOutput = $this->waitForProcessToken($first, $firstPipes[1], $firstPipes[2], 'RESULT ');
        $secondOutput = $this->waitForProcessToken($second, $secondPipes[1], $secondPipes[2], 'RESULT ');
        $firstError = stream_get_contents($firstPipes[2]);
        $secondError = stream_get_contents($secondPipes[2]);
        self::assertSame(0, proc_close($first), $firstError);
        self::assertSame(0, proc_close($second), $secondError);
        $results = [
            str_contains($firstOutput, 'RESULT started') ? 'started' : 'blocked',
            str_contains($secondOutput, 'RESULT started') ? 'started' : 'blocked',
        ];
        sort($results);
        self::assertSame(['blocked', 'started'], $results);
        self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')
            ->where('state', 'wire_started')->count());
        self::assertSame('paused', $fixture['document']->fresh()->processing_control_status);
    }

    #[Test]
    public function session_cost_ceiling_aggregates_multiple_documents_before_the_next_wire(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            config()->set('estimate-generation.generation.document_cost_limit_rub', '10.00000000');
            config()->set('estimate-generation.generation.session_cost_limit_rub', '1.00000000');
            $fixture = $this->fixture('10.00000000');
            $sibling = $this->siblingDocument($fixture, 'active', null);
            DB::table('estimate_generation_ai_usage')->insert([
                $this->usageRow($fixture, (string) Str::uuid(), '0.40000000'),
                $this->usageRow($sibling, (string) Str::uuid(), '0.40000000'),
            ]);
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);

            try {
                $store->markWireStarted(
                    $context->attemptId,
                    $fingerprint,
                    $owner,
                    new DateTimeImmutable,
                    (new DateTimeImmutable)->modify('+180 seconds'),
                    new AiCost('0.25000000', 'RUB', 'available'),
                );
                self::fail('Session cost ceiling allowed another wire.');
            } catch (DocumentUnitProcessingException $exception) {
                self::assertSame('session_cost_limit_reached', $exception->safeCode);
            }

            $document = $fixture['document']->fresh();
            self::assertSame('paused', $document->processing_control_status);
            self::assertSame('session_cost_limit_reached', $document->processing_control_reason);
            self::assertSame(0, (int) data_get($document->meta, 'session_cost_guard_confirmation_version'));
            self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function session_cost_ceiling_also_blocks_later_ai_stages_with_exact_scope(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            config()->set('estimate-generation.generation.session_cost_limit_rub', '1.00000000');
            $fixture = $this->fixture('10.00000000');
            $sibling = $this->siblingDocument($fixture, 'active', null);
            DB::table('estimate_generation_ai_usage')->insert([
                $this->usageRow($fixture, (string) Str::uuid(), '0.40000000'),
                $this->usageRow($sibling, (string) Str::uuid(), '0.70000000'),
            ]);

            $this->expectException(SessionAiCostLimitReached::class);
            app(SessionAiCostGuard::class)->authorize(
                (int) $fixture['organization']->id,
                (int) $fixture['project']->id,
                (int) $fixture['session']->id,
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function paid_response_without_usage_journal_pauses_before_the_next_wire(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('100.00000000');
            $now = new DateTimeImmutable;
            [$store, $orphanContext, $orphanFingerprint, $orphanOwner] = $this->physicalAttempt($fixture);
            $store->markWireStarted(
                $orphanContext->attemptId,
                $orphanFingerprint,
                $orphanOwner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('60.00000000', 'RUB', 'available'),
            );
            $store->storeResponse(
                $orphanContext->attemptId,
                $orphanFingerprint,
                $orphanOwner,
                ['parsed_envelope' => ['status' => 'ok']],
                'succeeded',
                200,
                10,
                'fixture-model',
                ['pricing_status' => 'available', 'currency' => 'RUB'],
            );
            [$store, $nextContext, $nextFingerprint, $nextOwner] = $this->physicalAttempt($fixture);

            try {
                $store->markWireStarted(
                    $nextContext->attemptId,
                    $nextFingerprint,
                    $nextOwner,
                    $now,
                    $now->modify('+180 seconds'),
                    new AiCost('50.00000000', 'RUB', 'available'),
                );
                self::fail('Missing usage journal allowed another paid request.');
            } catch (DocumentUnitProcessingException $exception) {
                self::assertSame('document_cost_limit_reached', $exception->safeCode);
            }

            self::assertSame('paused', $fixture['document']->fresh()->processing_control_status);
            self::assertSame('pre_wire', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $nextContext->attemptId)->value('state'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function stop_after_wire_allows_the_bounded_response_to_be_saved(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('100.00000000');
            $now = new DateTimeImmutable;
            $unitClaim = app(DocumentProcessingUnitStore::class)->claim(
                (int) $fixture['unit']->id,
                $fixture['sourceVersion'],
                $now,
                $now->modify('+180 seconds'),
                3,
            );
            self::assertTrue($unitClaim->acquired());
            [$store, $context, $fingerprint, $owner] = $this->physicalAttempt($fixture);
            $store->markWireStarted(
                $context->attemptId,
                $fingerprint,
                $owner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('1.00000000', 'RUB', 'available'),
            );
            $this->attachActiveRoleRun($fixture, $context->attemptId, $owner);
            $store->storeResponse(
                $context->attemptId,
                $fingerprint,
                $owner,
                ['parsed_envelope' => ['status' => 'ok']],
                'response_received',
                200,
                10,
                'fixture-model',
                ['pricing_status' => 'available', 'currency' => 'RUB'],
            );
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, $context->attemptId, '0.10000000'),
            );
            $store->markUsageRecorded($context->attemptId, $fingerprint);
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->once()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->once()->andReturn(['summary' => []]);
            $stop = new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
                app(DocumentUnitAggregateReconciler::class),
            );
            $result = $stop->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'stop-after-wire',
            );

            self::assertSame('accepted', $result->disposition);
            self::assertSame('processing', $fixture['document']->fresh()->status);
            self::assertSame('running', $fixture['unit']->fresh()->status->value);
            $fixture['document']->forceFill([
                'units_finalized_source_version' => $fixture['sourceVersion'],
                'units_reconciled_source_version' => $fixture['sourceVersion'],
                'units_reconcile_claim_token' => (string) Str::uuid(),
                'units_reconcile_lease_expires_at' => now()->addMinutes(5),
            ])->save();

            self::assertSame('completed', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $context->attemptId)->value('state'));
            self::assertTrue(app(DocumentProcessingUnitStore::class)->publish(
                $unitClaim,
                new DocumentUnitOutput(
                    version: 'stop-after-wire-publication-v1',
                    text: 'Полезный сохранённый результат',
                    confidence: 0.95,
                    normalizedPayload: ['independent_observations' => [['status' => 'ok']]],
                    unitType: $fixture['unit']->unit_type,
                    unitIndex: (int) $fixture['unit']->unit_index,
                    sourceVersion: $fixture['sourceVersion'],
                ),
                new DateTimeImmutable,
            ));
            self::assertSame('completed', $fixture['unit']->fresh()->status->value);
            self::assertSame('ready', $fixture['page']->fresh()->status);
            self::assertNull($fixture['document']->fresh()->units_finalized_source_version);
            self::assertNull($fixture['document']->fresh()->units_reconciled_source_version);
            self::assertNull($fixture['document']->fresh()->units_reconcile_claim_token);
            self::assertNull($fixture['document']->fresh()->units_reconcile_lease_expires_at);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function historical_completed_attempt_does_not_protect_the_current_pre_wire_stage_from_stop(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture('100.00000000');
            $now = new DateTimeImmutable;
            $claim = app(DocumentProcessingUnitStore::class)->claim(
                (int) $fixture['unit']->id,
                $fixture['sourceVersion'],
                $now,
                $now->modify('+180 seconds'),
                3,
            );
            self::assertTrue($claim->acquired());
            [$store, $oldContext, $oldFingerprint, $oldOwner] = $this->physicalAttempt($fixture);
            $store->markWireStarted(
                $oldContext->attemptId,
                $oldFingerprint,
                $oldOwner,
                $now,
                $now->modify('+180 seconds'),
                new AiCost('1.00000000', 'RUB', 'available'),
            );
            $store->storeResponse(
                $oldContext->attemptId,
                $oldFingerprint,
                $oldOwner,
                ['parsed_envelope' => ['status' => 'ok']],
                'succeeded',
                200,
                10,
                'fixture-model',
                ['pricing_status' => 'available', 'currency' => 'RUB'],
            );
            DB::table('estimate_generation_ai_usage')->insert(
                $this->usageRow($fixture, $oldContext->attemptId, '0.10000000'),
            );
            $store->markUsageRecorded($oldContext->attemptId, $oldFingerprint);
            [$store, $currentContext, $currentFingerprint, $currentOwner] = $this->physicalAttempt($fixture);
            $this->attachActiveRoleRun($fixture, $currentContext->attemptId, $currentOwner);
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->once()->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->twice()->andReturn($this->reviewRequiredReadiness());
            $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
            $this->app->forgetInstance(DocumentUnitAggregateReconciler::class);

            (new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
                app(DocumentUnitAggregateReconciler::class),
            ))->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'stop-current-pre-wire',
            );

            self::assertSame('superseded', $fixture['unit']->fresh()->status->value);
            self::assertFalse(DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $currentContext->attemptId)->exists());
            self::assertSame('completed', DB::table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $oldContext->attemptId)->value('state'));
            self::assertSame('completed', $fixture['document']->fresh()->processing_stage);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function systemic_breaker_terminates_every_pre_wire_unit_in_the_current_lineage(): void
    {
        $this->requirePostgres();
        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $fixture['unit']->forceFill(['locator' => [
                ...$fixture['unit']->locator,
                'content_type' => 'drawing',
            ]])->save();
            $createUnit = function (int $index, string $contentType) use ($fixture): EstimateGenerationProcessingUnit {
                return EstimateGenerationProcessingUnit::query()->create([
                    'organization_id' => $fixture['organization']->id,
                    'project_id' => $fixture['project']->id,
                    'session_id' => $fixture['session']->id,
                    'document_id' => $fixture['document']->id,
                    'unit_type' => 'pdf_page',
                    'unit_index' => $index,
                    'source_version' => $fixture['sourceVersion'],
                    'status' => 'pending',
                    'locator' => [
                        'source_kind' => 'pdf',
                        'source_version' => $fixture['sourceVersion'],
                        'coordinate_space' => 'pdf_page_pixels',
                        'artifact_path' => sprintf('org-%d/estimate-generation/pages/%s/%d.png', $fixture['organization']->id, $fixture['lineage'], $index),
                        'artifact_sha256' => 'sha256:'.hash('sha256', $fixture['lineage'].'-page-'.$index),
                        'artifact_version_id' => 'version-'.$fixture['lineage'].'-'.$index,
                        'content_type' => $contentType,
                    ],
                    'metadata' => ['processing_attempt_id' => $fixture['lineage']],
                ]);
            };
            $second = $createUnit(2, 'specification');
            $third = $createUnit(3, 'table');
            $store = app(DocumentProcessingUnitStore::class);
            $now = new DateTimeImmutable;
            $fingerprint = hash('sha256', 'systemic-lineage-failure');
            foreach ([$fixture['unit'], $second] as $candidate) {
                $claim = $store->claim(
                    (int) $candidate->id,
                    $fixture['sourceVersion'],
                    $now,
                    $now->modify('+180 seconds'),
                    3,
                );
                self::assertTrue($claim->acquired());
                self::assertTrue($store->fail(
                    $claim,
                    'vision_provider_response_invalid',
                    $fingerprint,
                    $now,
                    FailureCategory::Terminal,
                    true,
                ));
            }

            self::assertSame('cancelled', $fixture['document']->fresh()->processing_control_status);
            self::assertSame('failed', $third->fresh()->status->value);
            self::assertSame('breaker_stopped', $third->fresh()->failure_code);
            self::assertSame(0, $third->fresh()->metadata['actual_execution_count']);
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function fixture(?string $costLimit = null): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $sourceVersion = 'sha256:'.hash('sha256', (string) Str::uuid());
        $lineage = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'processing_documents',
            'processing_stage' => 'processing_documents',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'filename' => 'processing-control.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => sprintf('org-%d/estimate-generation/processing-control.pdf', $organization->id),
            'checksum_sha256' => substr($sourceVersion, 7),
            'source_version' => $sourceVersion,
            'page_count' => 1,
            'status' => 'processing',
            'processing_control_status' => 'active',
            'processing_control_source_version' => $sourceVersion,
            'processing_control_attempt_id' => $lineage,
            'processing_cost_limit' => $costLimit,
            'meta' => ['processing_attempt_id' => $lineage],
        ]);
        $unit = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 1,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => sprintf('org-%d/estimate-generation/pages/%s/1.png', $organization->id, $lineage),
                'artifact_sha256' => 'sha256:'.hash('sha256', $lineage.'-page-1'),
                'artifact_version_id' => 'version-'.$lineage,
            ],
            'metadata' => ['processing_attempt_id' => $lineage],
        ]);
        $page = EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id,
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'session_id' => $session->id,
            'processing_unit_id' => $unit->id,
            'source_version' => $sourceVersion,
            'page_number' => 1,
            'status' => 'queued',
        ]);

        return compact('organization', 'project', 'user', 'session', 'document', 'unit', 'page', 'sourceVersion', 'lineage')
            + ['source_version' => $sourceVersion];
    }

    /** @param array<string, mixed> $fixture */
    private function expandDocumentToPages(array $fixture, int $pageCount): void
    {
        $fixture['document']->forceFill(['page_count' => $pageCount])->save();
        foreach (range(2, $pageCount) as $pageNumber) {
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $fixture['organization']->id,
                'project_id' => $fixture['project']->id,
                'session_id' => $fixture['session']->id,
                'document_id' => $fixture['document']->id,
                'unit_type' => 'pdf_page',
                'unit_index' => $pageNumber,
                'source_version' => $fixture['sourceVersion'],
                'status' => 'pending',
                'locator' => [
                    'source_kind' => 'pdf',
                    'source_version' => $fixture['sourceVersion'],
                    'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => sprintf(
                        'org-%d/estimate-generation/pages/%s/%d.png',
                        $fixture['organization']->id,
                        $fixture['lineage'],
                        $pageNumber,
                    ),
                    'artifact_sha256' => 'sha256:'.hash('sha256', $fixture['lineage'].'-page-'.$pageNumber),
                    'artifact_version_id' => 'version-'.$fixture['lineage'].'-'.$pageNumber,
                ],
                'metadata' => ['processing_attempt_id' => $fixture['lineage']],
            ]);
            EstimateGenerationDocumentPage::query()->create([
                'document_id' => $fixture['document']->id,
                'organization_id' => $fixture['organization']->id,
                'project_id' => $fixture['project']->id,
                'session_id' => $fixture['session']->id,
                'processing_unit_id' => $unit->id,
                'source_version' => $fixture['sourceVersion'],
                'page_number' => $pageNumber,
                'status' => 'queued',
            ]);
        }
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function siblingDocument(array $fixture, string $controlStatus, ?string $controlReason): array
    {
        $sourceVersion = 'sha256:'.hash('sha256', (string) Str::uuid());
        $lineage = (string) Str::uuid();
        $document = EstimateGenerationDocument::query()->create([
            'session_id' => $fixture['session']->id,
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'user_id' => $fixture['user']->id,
            'filename' => 'large-'.$controlStatus.'.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => sprintf(
                'org-%d/estimate-generation/large-%s.pdf',
                $fixture['organization']->id,
                $controlStatus,
            ),
            'checksum_sha256' => substr($sourceVersion, 7),
            'source_version' => $sourceVersion,
            'page_count' => 1,
            'status' => 'processing',
            'processing_control_status' => $controlStatus,
            'processing_control_source_version' => $sourceVersion,
            'processing_control_attempt_id' => $lineage,
            'processing_control_reason' => $controlReason,
            'processing_cost_limit' => $controlStatus === 'paused' ? '1.00000000' : null,
            'meta' => ['processing_attempt_id' => $lineage],
        ]);
        $unit = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'session_id' => $fixture['session']->id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => 1,
            'source_version' => $sourceVersion,
            'status' => 'pending',
            'locator' => [
                'source_kind' => 'pdf',
                'source_version' => $sourceVersion,
                'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => sprintf(
                    'org-%d/estimate-generation/pages/%s/1.png',
                    $fixture['organization']->id,
                    $lineage,
                ),
                'artifact_sha256' => 'sha256:'.hash('sha256', $lineage.'-page-1'),
                'artifact_version_id' => 'version-'.$lineage,
            ],
            'metadata' => ['processing_attempt_id' => $lineage],
        ]);
        $page = EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id,
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'session_id' => $fixture['session']->id,
            'processing_unit_id' => $unit->id,
            'source_version' => $sourceVersion,
            'page_number' => 1,
            'status' => 'queued',
        ]);

        return [
            ...$fixture,
            ...compact('document', 'unit', 'page', 'sourceVersion', 'lineage'),
            'source_version' => $sourceVersion,
        ];
    }

    /** @return array{EloquentVisionPhysicalAttemptStore, AiOperationContext, string, string} */
    private function physicalAttempt(array $fixture): array
    {
        $context = new AiOperationContext(
            (string) Str::uuid(),
            (string) Str::uuid(),
            (int) $fixture['organization']->id,
            (int) $fixture['project']->id,
            (int) $fixture['session']->id,
            'understand_documents',
            'vision',
            1,
            (int) $fixture['document']->id,
            (int) $fixture['page']->id,
            (int) $fixture['unit']->id,
            $fixture['lineage'],
        );
        $fingerprint = hash('sha256', 'wire-'.$context->attemptId);
        $owner = (string) Str::uuid();
        $now = new DateTimeImmutable;
        $store = new EloquentVisionPhysicalAttemptStore(
            DB::connection(),
            new DocumentWireAuthorization(DB::connection()),
        );
        $store->claim($context, $fingerprint, $owner, $now, $now->modify('+180 seconds'));

        return [$store, $context, $fingerprint, $owner];
    }

    /** @return array<string, mixed> */
    private function usageRow(array $fixture, string $attemptId, string $cost): array
    {
        return [
            'attempt_id' => $attemptId,
            'correlation_id' => (string) Str::uuid(),
            'immutable_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'session_id' => $fixture['session']->id,
            'document_id' => $fixture['document']->id,
            'page_id' => $fixture['page']->id,
            'unit_id' => $fixture['unit']->id,
            'stage' => 'understand_documents',
            'operation' => 'vision',
            'attempt_ordinal' => 1,
            'provider' => 'timeweb',
            'requested_model' => 'fixture-model',
            'reported_model' => 'fixture-model',
            'usage_status' => 'measured',
            'status' => 'succeeded',
            'http_code' => 200,
            'input_tokens' => 1,
            'cached_input_tokens' => 0,
            'output_tokens' => 1,
            'reasoning_tokens' => 0,
            'image_count' => 0,
            'image_detail' => null,
            'page_count' => 0,
            'duration_ms' => 1,
            'price_snapshot' => json_encode([
                'source' => 'fixture',
                'version' => 'cost-ceiling-v1',
                'currency' => 'RUB',
                'input_per_million' => '0',
                'cached_input_per_million' => '0',
                'output_per_million' => '0',
                'reasoning_mode' => 'excluded_from_output',
                'effective_at' => now()->format('Y-m-d\\TH:i:sP'),
            ], JSON_THROW_ON_ERROR),
            'cost_amount' => $cost,
            'currency' => 'RUB',
            'pricing_status' => 'available',
            'created_at' => now(),
        ];
    }

    private function attachActiveRoleRun(array $fixture, string $physicalAttemptId, string $owner): void
    {
        DB::table('estimate_generation_ai_role_runs')->insert([
            'organization_id' => $fixture['organization']->id,
            'project_id' => $fixture['project']->id,
            'session_id' => $fixture['session']->id,
            'document_id' => $fixture['document']->id,
            'page_id' => $fixture['page']->id,
            'subject_type' => 'document_page',
            'subject_id' => (string) $fixture['page']->id,
            'subject_version' => $fixture['sourceVersion'],
            'role' => 'literal_observer',
            'status' => 'running',
            'model' => 'fixture-model',
            'prompt_contract_version' => 'fixture-contract-v1',
            'input_fingerprint' => hash('sha256', $physicalAttemptId.'-input'),
            'identity_fingerprint' => hash('sha256', $physicalAttemptId.'-identity'),
            'physical_attempt_id' => $physicalAttemptId,
            'owner_uuid' => $owner,
            'lease_expires_at' => now()->addMinutes(3),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewRequiredReadiness(): array
    {
        return [
            'can_generate' => false,
            'summary' => [
                'pending_count' => 0,
                'system_failure_count' => 0,
                'action_required_count' => 1,
                'items' => [],
            ],
        ];
    }

    private function requirePostgres(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }

    private function waitForProcessToken($process, $stdout, $stderr, string $token): string
    {
        stream_set_blocking($stdout, false);
        $output = '';
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $chunk = fread($stdout, 8192);
            if ($chunk !== false) {
                $output .= $chunk;
            }
            if (str_contains($output, $token)) {
                return $output;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail(trim((string) stream_get_contents($stderr)) ?: 'Control worker stopped before '.$token.'. Output: '.$output);
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        self::fail('Control worker timed out before '.$token.'.');
    }
}
