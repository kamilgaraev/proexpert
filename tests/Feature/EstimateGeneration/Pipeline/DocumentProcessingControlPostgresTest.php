<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ConfirmEstimateGenerationDocumentCost;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DispatchDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentCostJournalReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentWireAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StopEstimateGenerationDocumentProcessing;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
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
            $readiness->shouldReceive('evaluate')->twice()->andReturn(['summary' => []]);
            $service = new StopEstimateGenerationDocumentProcessing(
                $authorization,
                $readiness,
            );

            $first = $service->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'stop-idempotency-key',
            );
            $second = $service->handle(
                $fixture['session'],
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
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
            $readiness->shouldReceive('evaluate')->twice()->andReturn(['summary' => []]);
            $this->app->instance(AuthorizationService::class, $authorization);
            $this->app->instance(DocumentGenerationReadinessService::class, $readiness);
            $stop = new StopEstimateGenerationDocumentProcessing($authorization, $readiness);
            $stop->handle(
                $fixture['session'],
                $fixture['document'],
                $fixture['user'],
                0,
                $fixture['sourceVersion'],
                'stop-before-retry',
            );

            $retry = app(RetryEstimateGenerationDocument::class)->handle(
                $fixture['session']->fresh(),
                $fixture['document']->fresh(),
                $fixture['user'],
                0,
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
            $fixture = $this->fixture('0.10000000');
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $fixture['document']->forceFill([
                'status' => 'needs_review',
                'processing_stage' => 'quality_check',
                'processing_control_status' => 'paused',
                'processing_control_reason' => 'cost_limit_reached',
            ])->save();
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->times(4)->andReturnTrue();
            $readiness = Mockery::mock(DocumentGenerationReadinessService::class);
            $readiness->shouldReceive('evaluate')->times(4)->andReturn(['summary' => []]);
            $service = new ConfirmEstimateGenerationDocumentCost(
                $authorization,
                $readiness,
                app(DispatchDocumentProcessingUnits::class),
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

            self::assertSame('accepted', $first->disposition);
            self::assertSame('replayed', $second->disposition);
            self::assertSame('accepted', $third->disposition);
            self::assertSame('replayed', $delayedFirst->disposition);
            self::assertSame('0.50000000', (string) $fixture['document']->fresh()->processing_cost_limit);
            self::assertSame(2, (int) $fixture['document']->fresh()->processing_cost_confirmation_version);
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
            $store->markWireStarted($context->attemptId, $fingerprint, $owner, $now, $now->modify('+180 seconds'));
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
            $stop = new StopEstimateGenerationDocumentProcessing($authorization, $readiness);
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
            $readiness->shouldReceive('evaluate')->once()->andReturn(['summary' => []]);

            (new StopEstimateGenerationDocumentProcessing($authorization, $readiness))->handle(
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
