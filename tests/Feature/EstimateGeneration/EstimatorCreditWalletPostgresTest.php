<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentAiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorCreditAttemptAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Credits\EstimatorCreditRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimatorCreditWalletPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_an_unknown_legacy_paid_balance_keeps_legacy_billing_without_a_new_shared_reserve(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $policy = \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::class;
        self::assertTrue($policy::enabledForOrganization((int) $organization->id));
        $allocation = \App\Models\OrganizationResourceAllocation::query()->create(['organization_id' => $organization->id,
            'resource_slug' => 'extra_ai_estimates', 'limit_key' => 'ai_estimates_month', 'quantity' => 10,
            'source' => 'paid_addon', 'status' => 'active', 'period_start_at' => now()->subDay(), 'period_end_at' => now()->addDay(),
            'metadata' => ['commercial_order_id' => 'unknown-payment-origin']]);
        self::assertFalse($policy::enabledForOrganization((int) $organization->id));
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, $request->projectId, $request->sessionId, 0, (string) Str::uuid(), 'generation');
            self::fail('An unresolved legacy balance was silently converted to shared billing.');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_credit_legacy_balance_requires_reconciliation', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame('active', $allocation->fresh()->status);
        self::assertSame('10.00', $allocation->fresh()->quantity);
        $allocation->update(['period_end_at' => now()->subSecond()]);
        self::assertTrue($policy::enabledForOrganization((int) $organization->id));
    }

    public function test_manual_generation_retry_requires_a_new_quote_and_replays_one_approved_operation(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'failed', 'resume_status' => 'generating']);
        $retry = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession::class);
        $command = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand(
            (int) $session->id, (int) $organization->id, (int) $session->project_id, 0, $actor);
        try {
            $retry->handle($command);
            self::fail('An old client started paid retry without a quote.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->count());
            self::assertSame('failed', $session->fresh()->status->value);
        }
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:approved-retry');
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'generation');
        $approved = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand(
            (int) $session->id, (int) $organization->id, (int) $session->project_id, 0, $actor, ['request_id' => $id, 'quote_id' => $quote['quote_id']]);
        $started = $retry->handle($approved);
        self::assertSame('generating', $started->status->value);
        self::assertSame($id, $started->input_payload['generation_attempt_id']);
        self::assertSame($id, $started->input_payload['generation_credit_operation_id']);
        $replayed = $retry->handle($approved);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_a_failed_document_releases_the_operation_and_blocks_units_even_while_generation_is_pending(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'processing_documents']);
        $source = 'sha256:'.hash('sha256', 'failed-document-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'failed.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'fixture/private/failed.pdf',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'pdf', 'source_version' => $source, 'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'fixture/private/failed/page.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'failed-fixture-page'), 'artifact_version_id' => 'fixture.v1'],
            'metadata' => ['processing_attempt_id' => $lineage]]);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:failed-document');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $id = (string) Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $id, $quote['quote_id'], 'generation'));
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertTrue($binding->canDispatch($unit->fresh()));
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot::capture($session,
            'document_manifest', $lineage, (int) $document->id, $source);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\FailDocumentProcessing::class)->handle($snapshot, 'fixture_failure');
        self::assertSame('failed', $document->fresh()->status);
        self::assertSame('cancelled', $document->fresh()->processing_control_status);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        self::assertArrayNotHasKey('credit_operation_id', $unit->fresh()->metadata);
        self::assertSame('failed', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        self::assertSame(0, (int) DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('consumed_units_minor'));
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->count());
    }

    public function test_service_expense_limit_is_independent_of_a_large_customer_balance(): void
    {
        [$organization] = $this->fixture();
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:expense-guard');
        config(['estimate-generation.credits.service_daily_expense_limit_rub' => '0.02']);
        try {
            DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorServiceExpenseGuard::class)
                ->admit((int) $organization->id, 30000));
            self::fail('The service expense ceiling was bypassed by a large user balance.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_service_expense_limit_reached', $exception->reason);
        }
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->count());
    }

    public function test_document_units_are_paused_until_the_exact_manifest_is_approved_and_mutation_invalidates_it(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $sourceVersion = 'sha256:'.hash('sha256', 'fixture-pdf');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'session_id' => $session->id, 'organization_id' => $organization->id, 'project_id' => $session->project_id, 'user_id' => $actor->id,
            'filename' => 'fixture.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'fixture/private.pdf',
            'checksum_sha256' => substr($sourceVersion, 7), 'source_version' => $sourceVersion, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $sourceVersion,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'document_id' => $document->id,
            'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $sourceVersion, 'status' => 'pending',
            'locator' => ['source_kind' => 'pdf', 'source_version' => $sourceVersion, 'coordinate_space' => 'pdf_page_pixels',
                'artifact_path' => 'fixture/private/page.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'fixture-page'), 'artifact_version_id' => 'fixture.v1'],
            'metadata' => ['processing_attempt_id' => $lineage]]);
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertFalse($binding->canDispatch($unit));
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:document-quote');
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $requestId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation'));
        self::assertSame($requestId, $unit->fresh()->metadata['credit_operation_id']);
        self::assertTrue($binding->canDispatch($unit->fresh()));
        $metadata = $unit->fresh()->metadata;
        $metadata['processing_attempt_id'] = (string) Str::uuid();
        $unit->update(['metadata' => $metadata]);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        $metadata['processing_attempt_id'] = $lineage;
        $unit->update(['metadata' => $metadata]);
        $locator = $unit->fresh()->locator;
        $locator['artifact_sha256'] = 'sha256:'.hash('sha256', 'different-page');
        $unit->update(['locator' => $locator]);
        self::assertFalse($binding->canDispatch($unit->fresh()));
        $locator['artifact_sha256'] = 'sha256:'.hash('sha256', 'fixture-page');
        $unit->update(['locator' => $locator, 'status' => 'running', 'claim_token' => (string) Str::uuid(),
            'attempt_count' => 1, 'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.ocr.timeweb.api_key' => 'fixture-key',
            'estimate-generation.ocr.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(function () use ($actor, $session) {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                ->handle($session->fresh(), 0, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::Cancelled, $actor);

            return \Illuminate\Support\Facades\Http::response(['model' => 'openai/gpt-6-luna',
                'choices' => [['message' => ['content' => '{"pages":[{"page_number":1,"text":"fixture"}]}']]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]], 200);
        });
        $correlationId = (string) Str::uuid();
        $context = new AiOperationContext($correlationId, (string) Str::uuid(), (int) $organization->id, (int) $session->project_id,
            (int) $session->id, 'understand_documents', 'ocr', 1, documentId: (int) $document->id, unitId: (int) $unit->id,
            processingLineageId: $lineage, creditOperationId: $requestId);
        $recognition = app(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface::class)
            ->recognize(new \App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput('fixture-image', 'image/png', 'fixture.png', 1, $context));
        self::assertSame('openai/gpt-6-luna', $recognition->model);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        self::assertSame('cancelled', $session->fresh()->status->value);
        self::assertSame('cancelled', DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->value('status'));
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('organization_id', $organization->id)->count());
        $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('organization_id', $organization->id)->firstOrFail();
        self::assertSame(20250, $usage->cost_micro_rub);
        self::assertTrue($usage->metadata['late_provider_result']);
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session, $requestId));
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
    }

    public function test_server_quote_is_scoped_idempotent_and_start_reserve_is_atomic(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $credits = app(AICreditService::class);
        $credits->grant($organization, 1000000, 'purchase', null, 'test:server-quote');
        $requestId = (string) Str::uuid();
        $quote = $operations->quote($actor, $request->projectId, $request->sessionId, 0, $requestId, 'generation');
        self::assertSame($quote, $operations->quote($actor, $request->projectId, $request->sessionId, 0, $requestId, 'generation'));
        self::assertSame('openai/gpt-6-luna', $quote['model']);
        self::assertGreaterThan(0, $quote['maximum_calls']);
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        try {
            DB::transaction(function () use ($operations, $actor, $session, $quote, $requestId): void {
                $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation');
                throw new \RuntimeException('forced_start_rollback');
            });
            self::fail('The simulated rollback was lost.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_start_rollback', $exception->getMessage());
        }
        self::assertSame('quoted', DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->value('status'));
        self::assertSame(0, DB::table('ai_credit_reservations')->where('request_id', 'estimator:generation:'.$requestId)->count());
        self::assertSame($requestId, DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation')));
        self::assertSame($requestId, DB::transaction(fn () => $operations->begin($actor, $session, 0, $requestId, $quote['quote_id'], 'generation')));
        self::assertSame(1, DB::table('ai_credit_reservations')->where('request_id', 'estimator:generation:'.$requestId)->count());
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $requestId)->first();
        $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($operation->reservation_id);
        $credits->cancel($reservation);
        self::assertSame(1000000, $credits->balance($organization)['available_minor']);
    }

    public function test_a_physical_call_is_bound_to_one_reserved_operation_and_a_rollback_does_not_consume_its_slot(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:physical-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        DB::table('estimate_generation_credit_operations')->insert(['operation_id' => $request->operationId,
            'organization_id' => $request->organizationId, 'project_id' => $request->projectId, 'session_id' => $request->sessionId,
            'actor_user_id' => $actor->id, 'kind' => $request->kind, 'status' => 'reserved', 'approved_state_version' => $request->stateVersion,
            'input_hash' => $request->inputHash, 'source_version' => $request->sourceVersion, 'manifest_hash' => $request->manifestHash,
            'plan_hash' => $request->planHash, 'processing_plan' => CanonicalPipelineJson::encode($this->plan()),
            'credit_request' => CanonicalPipelineJson::encode($request->identity()), 'quote_id' => $reservation->ai_credit_quote_id,
            'reservation_id' => $reservation->id, 'maximum_provider_cost_micro_rub' => $request->maximumProviderCostMicroRub,
            'maximum_calls' => $request->maximumCalls, 'created_at' => now(), 'updated_at' => now()]);
        $attemptId = (string) Str::uuid();
        $admission = app(EstimatorCreditAttemptAdmission::class);
        $arguments = [$request->operationId, $attemptId, $request->organizationId, $request->projectId, $request->sessionId,
            'estimate_composition', new AiCost('0.030000', 'RUB', 'available')];
        try {
            DB::transaction(function () use ($admission, $arguments): void {
                $admission->admit(...$arguments);
                throw new \RuntimeException('forced_pre_wire_refusal');
            });
            self::fail('Expected the simulated pre-wire refusal.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_pre_wire_refusal', $exception->getMessage());
        }
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        $catalog = app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class);
        $changedTariff = $request->supplierTariff;
        $changedTariff['version'] = 'changed-after-approval';
        $changedCatalog = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog([
            'estimate_composition' => ['timeweb' => ['openai/gpt-6-luna' => [$changedTariff]]],
        ]);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class, $changedCatalog);
        try {
            DB::transaction(fn () => $admission->admit(...$arguments));
            self::fail('A changed supplier tariff was sent without new approval.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached $exception) {
            self::assertSame('estimator_credit_supplier_tariff_changed', $exception->reason);
            self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        } finally {
            $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class, $catalog);
        }
        self::assertSame((int) $reservation->id, DB::transaction(fn () => $admission->admit(...$arguments)));
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $request->operationId)->count());
        $usage = new AiUsageData(new AiOperationContext((string) Str::uuid(), $attemptId, $request->organizationId,
            $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $request->operationId),
            'timeweb', 'openai/gpt-6-luna', 'succeeded', 10, usageStatus: 'measured', inputTokens: 1000, outputTokens: 100,
            reasoningTokens: 20, priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff));
        $store = app(EloquentAiUsageStore::class);
        $store->record($usage);
        $store->record($usage);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->count());
        self::assertSame(20250, (int) DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->value('cost_micro_rub'));
        self::assertSame('recorded', DB::table('estimate_generation_credit_attempts')->where('attempt_id', $attemptId)->value('status'));
        self::assertSame(substr($usage->immutableFingerprint, 7), DB::table('estimate_generation_credit_attempts')->where('attempt_id', $attemptId)->value('usage_fingerprint'));
        $badContext = new AiOperationContext((string) Str::uuid(), (string) Str::uuid(), $request->organizationId,
            $request->projectId, $request->sessionId, 'plan_work_items', 'estimate_composition', 1, creditOperationId: $request->operationId);
        try {
            $store->record(new AiUsageData($badContext, 'timeweb', 'openai/gpt-6-luna', 'succeeded', 10,
                usageStatus: 'measured', inputTokens: 1, priceSnapshot: AiPriceSnapshot::fromArray($request->supplierTariff)));
            self::fail('A non-admitted physical call entered the shared wallet.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation) {
            self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('attempt_id', $badContext->attemptId)->count());
        }
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $settlement = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class);
        $knownItem = ['key' => 'work:known', 'quantity' => '3', 'quantity_evidence' => ['source_type' => 'user_input']];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$knownItem]]]]]];
        $draft['source_input_version'] = $request->sourceVersion;
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        try {
            DB::transaction(function () use ($settlement, $session, $request, $draft): void {
                $session->update(['draft_payload' => $draft]);
                $settlement->publish($session, $request->operationId, $draft);
                throw new \RuntimeException('forced_publication_rollback');
            });
            self::fail('The simulated publication rollback was lost.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_publication_rollback', $exception->getMessage());
        }
        self::assertNull($session->fresh()->draft_payload);
        self::assertSame('reserved', $reservation->fresh()->status);
        self::assertSame('running', DB::table('estimate_generation_credit_operations')->where('operation_id', $request->operationId)->value('status'));
        $receipt = DB::transaction(fn () => $settlement->publish($session->fresh(), $request->operationId, $draft));
        self::assertSame(50, $receipt['consumed_units_minor']);
        self::assertEquals($receipt, DB::transaction(fn () => $settlement->publish($session->fresh(), $request->operationId, $draft)));
        // A known settled receipt can be redelivered without creating another supplier expense.
        $store->record($usage);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('usage_key', $attemptId)->count());
        $arguments[1] = (string) Str::uuid();
        $this->expectException(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached::class);
        DB::transaction(fn () => $admission->admit(...$arguments));
    }

    public function test_estimator_reserves_the_shared_wallet_even_when_assistant_enforcement_is_disabled(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:shared-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        self::assertSame($quote, $credits->quoteEstimator($organization, $actor, $request));
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        self::assertGreaterThan(0, $reservation->reserved_minor);
        self::assertSame($quote['max_units_minor'], $reservation->reserved_minor);
        self::assertSame($reservation->id, $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request)->id);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        self::assertSame(50, $credits->finalize($reservation, useful: true));
        self::assertSame(50, $credits->finalize($reservation, useful: true));
        self::assertSame(9950, $credits->balance($organization)['available_minor']);
    }

    public function test_questions_only_do_not_charge_even_a_successful_provider_call(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:question-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $reservation = $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
        $credits->recordProviderCost($reservation, 6075, 'timeweb', 'openai/gpt-6-luna', 'estimate_composer', ['usage_key' => 'physical:1'], true);
        self::assertSame(0, $credits->finalize($reservation, useful: false));
        self::assertSame(10000, $credits->balance($organization)['available_minor']);
    }

    public function test_quote_for_other_inputs_cannot_reserve_and_revoked_membership_is_checked_again(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $credits = app(AICreditService::class);
        $credits->grant($organization, 10000, 'purchase', null, 'test:identity-wallet');
        $quote = $credits->quoteEstimator($organization, $actor, $request);
        $changed = new EstimatorCreditRequest($request->organizationId, $request->projectId, $request->sessionId, $request->actorUserId,
            $request->operationId, $request->kind, $request->stateVersion, str_repeat('b', 64), $request->sourceVersion,
            $request->manifestHash, $request->planHash, $request->maximumProviderCostMicroRub, $request->maximumCalls, $request->supplierTariff);
        try {
            $credits->beginEstimator($organization, $actor, $quote['quote_id'], $changed);
            self::fail('A quote was used for different estimator inputs.');
        } catch (\DomainException) {
            self::assertSame(10000, $credits->balance($organization)['available_minor']);
        }
        $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]);
        $this->expectException(\DomainException::class);
        $credits->beginEstimator($organization, $actor, $quote['quote_id'], $request);
    }

    public function test_section_rebuild_requires_its_own_approved_target_and_replays_without_another_reserve(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $draft = ['source_input_version' => 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session),
            'local_estimates' => [['key' => 'roof:level2', 'title' => 'Roof', 'sections' => []], ['key' => 'walls:level1', 'title' => 'Walls', 'sections' => []]]];
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->update(['status' => 'estimate_review_required', 'draft_payload' => (new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft)->seal($draft)]);
        $rebuild = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RebuildGeneratedSection::class);
        try {
            $rebuild->handle($session, 0, 'roof:level2', $actor);
            self::fail('Rebuild started without an approved quote.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:approved-rebuild');
        $id = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'rebuild', ['local_estimate_key' => 'roof:level2']);
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id']];
        try {
            $rebuild->handle($session, 0, 'walls:level1', $actor, $approval);
            self::fail('A rebuild quote was accepted for another section.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
            self::assertSame(0, $session->fresh()->state_version);
        }
        $started = $rebuild->handle($session, 0, 'roof:level2', $actor, $approval);
        self::assertSame('generating', $started->status->value);
        self::assertSame($id, $started->input_payload['generation_credit_operation_id']);
        self::assertSame('roof:level2', $started->input_payload['rebuild_section_key']);
        $replayed = $rebuild->handle($session, 0, 'roof:level2', $actor, $approval);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
    }

    private function fixture(): array
    {
        $entitlements = \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $organization->id]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'state_version' => 0, 'status' => 'draft', 'input_payload' => []]);
        $inputHash = (new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session);
        $request = new EstimatorCreditRequest((int) $organization->id, (int) $project->id, (int) $session->id, (int) $actor->id,
            (string) Str::uuid(), 'generation', 0, $inputHash, 'sha256:'.$inputHash, str_repeat('c', 64), hash('sha256', CanonicalPipelineJson::encode($this->plan())),
            1800000, 1, app(\App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog::class)
                ->resolve('estimate_composition', 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable)->toArray());

        return [$organization, $actor, $request];
    }

    private function seedGlobalSettings(): void
    {
        if (DB::table('estimate_generation_setting_snapshots')->where('scope', 'global')->whereNull('organization_id')->exists()) {
            return;
        }
        $snapshot = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData::fromArray([
            'scope' => 'global', 'organization_id' => null, 'expected_version' => 0, 'idempotency_key' => (string) Str::ulid(),
            'models' => ['vision' => 'openai/gpt-6-luna', 'classification' => 'openai/gpt-6-luna', 'normative_matching' => 'openai/gpt-6-luna'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 60, 'classification' => 60, 'normative_matching' => 60],
            'retries' => ['vision' => 0, 'classification' => 0, 'normative_matching' => 0],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB']])->snapshot();
        $hash = \App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash::calculate($snapshot);
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Fixture', 'email' => Str::uuid().'@example.test',
            'password' => 'not-used', 'created_at' => now(), 'updated_at' => now()]);
        $snapshotId = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB',
            'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $snapshotId,
            'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
    }

    private function plan(): array
    {
        return ['roles' => ['estimate_composition' => ['max_calls' => 1, 'maximum_cost_micro_rub' => 1800000]]];
    }
}
