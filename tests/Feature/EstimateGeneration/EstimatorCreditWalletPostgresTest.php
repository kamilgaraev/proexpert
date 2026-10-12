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
    public function test_simple_paid_understanding_keeps_current_conflict_questions_without_any_model_expense(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)->handle(
            $actor, $scope->projectId, $scope->sessionId, 0, (string) Str::uuid(), ['entities' => [
                ['key' => 'room-a', 'type' => 'room', 'name' => 'Первое помещение'],
                ['key' => 'room-b', 'type' => 'room', 'name' => 'Второе помещение'],
            ]]);
        $models = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository::class);
        $facts = $models->snapshot((int) $organization->id, $scope->projectId, $scope->sessionId)->facts;
        self::assertCount(2, $facts);
        $conflict = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict::between(
            'conflict:manual:scope-confirmation', $facts, 'manual_scope_confirmation_required');
        $models->saveSourceModel([], [], [], [$conflict]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:simple-understanding');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 1, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session->fresh(), 1, $operationId, $quote['quote_id'], 'generation'));
        $wire = new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(1, null, $operationId);
        $coordinator = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingCoordinator::class);
        $result = $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 1, $wire);
        self::assertSame(0, $result->providerCalls);
        self::assertSame([$conflict->id], array_column($result->questions, 'conflict_id'));
        $factIds = array_column($facts, 'id');
        sort($factIds, SORT_STRING);
        self::assertSame($factIds, $result->questions[0]['fact_ids']);
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope->sessionId)->count());
        $replayed = $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 2, $wire);
        self::assertEquals($result->questions, $replayed->questions);
        self::assertSame([$conflict->id], array_column($replayed->conflicts, 'id'));
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
        $row = DB::table('estimate_generation_project_understanding_runs')->where('session_id', $scope->sessionId)->sole();
        $payload = json_decode($row->result_payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectUnderstandingFingerprint::CONTRACT, $payload['result_contract']);
        $payload['questions'][0]['fact_ids'] = [];
        DB::table('estimate_generation_project_understanding_runs')->where('id', $row->id)
            ->update(['result_payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project understanding fingerprint collision.');
        $coordinator->refresh((int) $organization->id, $scope->projectId, $scope->sessionId, (string) Str::uuid(), 3, $wire);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('auditPartitionApprovals')]
    public function test_real_audit_parts_use_the_original_reserve_and_reject_an_unapproved_expansion(bool $enoughParts, bool $largeContext = false): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['selected_sections' => $enoughParts ? ['finishing', 'structure', 'equipment'] : ['finishing']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:audit-parts');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $operationId, $quote['quote_id'], 'generation'));
        $responses = [];
        for ($index = 0; $index < 3; $index++) {
            $responses[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-part-'.$index, 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"accepted":true,"findings":[]}']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], JSON_THROW_ON_ERROR));
        }
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $httpHistory = [];
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $handler->push(\GuzzleHttp\Middleware::history($httpHistory));
        $wire = new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
            httpClient: new \GuzzleHttp\Client(['handler' => $handler]));
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class, $wire);
        $items = array_map(static fn (int $index): array => ['key' => 'work:'.$index, 'name' => 'Работа '.$index, 'quantity' => '1', 'unit' => 'm2'], range(1, 65));
        $facts = $evidence = [];
        if ($largeContext) {
            for ($bucket = 0; $bucket < 3; $bucket++) {
                for ($suffix = 0; ; $suffix++) {
                    $id = 'fact:context:'.$bucket.':'.$suffix;
                    if ((int) hexdec(substr(hash('sha256', $id), 0, 7)) % 3 === $bucket) {
                        break;
                    }
                }
                $facts[] = ['id' => $id, 'value' => str_repeat('x', 200000)];
                $evidence[] = ['fact_id' => $id, 'locator' => ['page' => $bucket + 1, 'evidence_id' => 'evidence:'.$bucket]];
            }
        }
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, str_repeat('a', 64), 0, $facts, [],
            ['local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]], $evidence,
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $operationId));
        $cycles = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::class);
        if ($largeContext) {
            try {
                $cycles->apply($input);
                self::fail('An indivisible pathological source reached tokenization or wire.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_audit_context_requires_source_split', $exception->getMessage());
                self::assertCount(0, $httpHistory);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } elseif (! $enoughParts) {
            try {
                $cycles->apply($input);
                self::fail('The auditor expanded the approved part plan.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_audit_parts_exceed_approved_plan', $exception->getMessage());
                self::assertCount(0, $httpHistory);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } else {
            $first = $cycles->apply($input);
            self::assertSame('accepted', $first['audit']['status']);
            self::assertCount(3, $httpHistory);
            self::assertSame($first, $cycles->apply($input));
            self::assertCount(3, $httpHistory);
            self::assertSame(3, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            self::assertSame(3, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        }
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
    }

    public static function auditPartitionApprovals(): array
    {
        return [[true], [false], [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('engineerPartitionApprovals')]
    public function test_real_engineer_candidate_identity_matches_the_paid_physical_attempt_and_replays_once(int $linkCount, bool $enoughParts, bool $complex = true, bool $largeContext = false): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $scope] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $session = EstimateGenerationSession::query()->findOrFail($scope->sessionId);
        $session->update(['input_payload' => ['profile_id' => $complex ? 'industrial' : 'construction',
            'selected_sections' => $enoughParts ? ['structure', 'finishing'] : ['structure']]]);
        app(AICreditService::class)->grant($organization, 5000000, 'purchase', null, 'test:engineer-identity');
        $operationId = (string) Str::uuid();
        $operations = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $quote = $operations->quote($actor, $scope->projectId, $scope->sessionId, 0, $operationId, 'generation');
        DB::transaction(fn () => $operations->begin($actor, $session, 0, $operationId, $quote['quote_id'], 'generation'));
        $history = [];
        $version = 'sha256:'.str_repeat('a', 64);
        $facts = $links = [];
        for ($index = 0; $index < $linkCount; $index++) {
            foreach (['a', 'b'] as $suffix) {
                $facts[] = ['id' => 'fact:'.$index.':'.$suffix, 'source_version' => $version, 'current' => true];
            }
            $links[] = ['id' => 'link:room-'.$index, 'fact_ids' => ['fact:'.$index.':a', 'fact:'.$index.':b']];
        }
        if ($largeContext) {
            for ($index = 0; $index < 140; $index++) {
                $facts[] = ['id' => 'fact:unrelated:'.$index, 'source_version' => $version, 'current' => true,
                    'entity_id' => 'room:unrelated:'.$index, 'value' => str_repeat('x', 4500)];
            }
        }
        $parts = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisBatchPlanner)->plan($links, []);
        $responses = [];
        foreach ($parts as $part) {
            $content = json_encode(['accepted_link_ids' => array_column($part['links'], 'id'), 'question_conflict_ids' => []], JSON_THROW_ON_ERROR);
            $responses[] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-engineer', 'object' => 'chat.completion', 'created' => 1, 'model' => 'openai/gpt-6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], JSON_THROW_ON_ERROR));
        }
        $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $handler->push(\GuzzleHttp\Middleware::history($history));
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-not-a-real-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://timeweb.example.test/v1']);
        $this->app->instance(\App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient::class,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebRerankWireClient(
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\TimewebChatCompletionPayloadFactory::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard::class),
                app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\DurableAiPhysicalResponseStore::class),
                httpClient: new \GuzzleHttp\Client(['handler' => $handler])));
        $input = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput(
            (int) $organization->id, $scope->projectId, $scope->sessionId, [$version],
            $facts,
            [], [], ['arbiter' => [], 'geometry_expert' => []],
            \App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis::PROMPT_CONTRACT,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope(0, null, $operationId));
        $engineer = app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\RunProjectSynthesis::class);
        if (! $enoughParts) {
            try {
                $engineer->run($input, $links, []);
                self::fail('Unapproved engineer parts were sent.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('project_synthesis_parts_exceed_approved_plan', $exception->getMessage());
                self::assertCount(0, $history);
                self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            }
        } else {
            $first = $engineer->run($input, $links, []);
            self::assertCount($linkCount, $first->acceptedLinkIds);
            self::assertSame($first->toArray(), $engineer->run($input, $links, [])->toArray());
            self::assertCount(count($parts), $history);
            self::assertSame(count($parts), DB::table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->count());
            self::assertSame(count($parts), DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
            if ($largeContext) {
                $sent = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $sentInput = json_decode($sent['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR)['project'];
                self::assertSame(142, $sentInput['context_manifest']['total_facts']);
                self::assertSame($input->fingerprint(), $sentInput['context_manifest']['parent_fingerprint']);
                self::assertSame(['fact:0:a', 'fact:0:b'], array_column($sentInput['facts'], 'id'));
            }
        }
        DB::transaction(fn () => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperationSettlement::class)
            ->abort($session->fresh(), $operationId, 'fixture_finished'));
    }

    public static function engineerPartitionApprovals(): array
    {
        return [[1, true], [65, true], [65, false], [1, false, false], [1, true, true, true]];
    }

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
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
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
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
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
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
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
        self::assertSame(0, DB::table('estimate_generation_credit_attempts')->where('organization_id', $organization->id)->count());
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

    public function test_native_spreadsheet_dispatch_is_free_but_ocr_fallback_still_requires_an_approved_operation(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'native-queue-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'quantities.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => 'fixture/private/native.xlsx', 'checksum_sha256' => substr($source, 7), 'source_version' => $source,
            'status' => 'queued', 'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $base = ['organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'spreadsheet_sheet', 'source_version' => $source, 'status' => 'pending',
            'metadata' => ['processing_attempt_id' => $lineage]];
        $locator = ['source_kind' => 'spreadsheet', 'source_version' => $source, 'coordinate_space' => 'spreadsheet_cells',
            'artifact_path' => 'fixture/private/sheet.json', 'artifact_sha256' => 'sha256:'.hash('sha256', 'native-artifact'),
            'artifact_version_id' => 'fixture.v1', 'content_type' => 'application/json', 'artifact_kind' => 'spreadsheet_sheet', 'artifact_schema_version' => 1];
        $native = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create($base + ['unit_index' => 1, 'locator' => $locator]);
        $fallback = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create($base + ['unit_index' => 2,
            'locator' => array_replace($locator, ['content_type' => 'image/png', 'artifact_path' => 'fixture/private/sheet.png'])]);
        $binding = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class);
        self::assertTrue($binding->canDispatch($native));
        self::assertFalse($binding->canDispatch($fallback));
        $store = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore::class);
        $due = $store->dueForDocument((int) $document->id, $source, new \DateTimeImmutable, 16);
        self::assertSame([(int) $native->id], array_map(static fn ($candidate): int => $candidate->unitId, $due));
        $dispatched = 0;
        self::assertTrue($store->dispatchIfAllowed($due[0], new \DateTimeImmutable, (new \DateTimeImmutable)->modify('+300 seconds'),
            static function () use (&$dispatched): void {
                $dispatched++;
            }));
        self::assertSame(1, $dispatched);
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_confirmed_free_input_can_start_one_approved_generation_and_only_clears_the_payment_planning_blocker(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        config(['estimate-generation.universal_enabled' => true]);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'input_review_required', 'input_payload' => ['description' => 'Ремонт помещения: сначала подтвердить состав и размеры',
            'generation_mode' => 'ai_assisted', 'evaluation_mode' => 'universal', 'profile_id' => 'renovation', 'price_policy' => 'catalog',
            'selected_sections' => ['finishing'], 'planning_review' => ['status' => 'blocked', 'limitations' => ['planning_requires_paid_approval', 'unresolved_scope']]]]);
        $confirmed = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
            ->handle($session, 0, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        self::assertSame('ready_to_generate', $confirmed->status->value);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:confirmed-input');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, $confirmed->state_version, $id, 'generation');
        $result = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)
            ->handle($confirmed, $confirmed->state_version, 'ai_assisted', $actor, creditApproval: ['request_id' => $id, 'quote_id' => $quote['quote_id']]);
        self::assertTrue($result->successful);
        self::assertSame('generating', $result->session->status->value);
        self::assertSame(['unresolved_scope'], $result->session->input_payload['planning_review']['limitations']);
        self::assertSame('blocked', $result->session->input_payload['planning_review']['status']);
        self::assertSame($id, $result->session->input_payload['generation_credit_operation_id']);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
    }

    public function test_free_document_completion_stops_before_unapproved_project_planning(): void
    {
        [$organization, $actor, $request] = $this->fixture();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $session->update(['status' => 'processing_documents']);
        $readiness = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class);
        $readiness->method('evaluate')->willReturn(['can_generate' => true, 'summary' => ['pending_count' => 0]]);
        $reconcile = new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments(
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration::class), $readiness,
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline::class));
        $completed = DB::transaction(fn () => $reconcile->reconcile($session));
        self::assertSame('input_review_required', $completed->status->value);
        self::assertSame('project_planning_requires_approval', $completed->failure_code);
        self::assertSame(['planning_requires_paid_approval'], $completed->input_payload['planning_review']['limitations']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
    }

    public function test_documents_only_operation_reserves_one_manifest_replays_and_completes_without_charging_or_generating(): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'documents-only-fixture');
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'room.png', 'mime_type' => 'image/png', 'storage_path' => 'fixture/private/room.png',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'raster_image', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'image', 'source_version' => $source, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'fixture/private/room.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'room-artifact'),
                'artifact_version_id' => 'fixture.v1', 'content_type' => 'image/png'], 'metadata' => ['processing_attempt_id' => $lineage]]);
        $starter = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\StartEstimatorDocumentOperation::class);
        $ignored = $document->replicate();
        $ignored->fill(['filename' => 'ignored.png', 'source_version' => 'sha256:'.hash('sha256', 'ignored-document'),
            'checksum_sha256' => hash('sha256', 'ignored-document'), 'status' => 'ignored'])->save();
        try {
            $starter->handle($actor, (int) $session->project_id, (int) $session->id, ['state_version' => 0]);
            self::fail('An unapproved document operation was started');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        }
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:documents-only');
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        self::assertSame(0, $quote['min_units_minor']);
        self::assertGreaterThan(0, $quote['max_units_minor']);
        $cached = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        self::assertSame($quote['quote_id'], $cached['quote_id']);
        self::assertSame(0, $cached['min_units_minor']);
        $approval = ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0];
        $started = $starter->handle($actor, (int) $session->project_id, (int) $session->id, $approval);
        self::assertSame('processing_documents', $started->status->value);
        self::assertFalse($started->input_payload['generation_requested']);
        self::assertSame($id, $unit->fresh()->metadata['credit_operation_id']);
        $replayed = $starter->handle($actor, (int) $session->project_id, (int) $session->id, $approval);
        self::assertSame($started->state_version, $replayed->state_version);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
        $complete = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StopEstimateGenerationDocumentProcessing::class)
            ->handle($started, $ignored, $actor, $started->state_version, $ignored->source_version, (string) Str::uuid());
        self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class)->canDispatch($unit->fresh()));
        self::assertTrue($complete->handle($started));
        self::assertSame('reserved', DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->value('status'));
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
                ->quote($actor, (int) $session->project_id, (int) $session->id, $started->state_version, (string) Str::uuid(), 'generation');
            self::fail('A new paid operation raced the active document manifest');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_credit_documents_processing_active', $exception->getMessage());
        }
        $unit->update(['status' => 'running', 'claim_token' => (string) Str::uuid(), 'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.ocr.timeweb.api_key' => 'fixture-not-real', 'estimate-generation.ocr.timeweb.base_uri' => 'https://fixture.invalid/v1']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://fixture.invalid/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
            'model' => 'openai/gpt-6-luna', 'choices' => [['message' => ['content' => '{"pages":[{"page_number":1,"text":"Нужны размеры"}]}']]],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50, 'total_tokens' => 250]], 200)]);
        $context = new AiOperationContext((string) Str::uuid(), (string) Str::uuid(), (int) $organization->id, (int) $session->project_id,
            (int) $session->id, 'understand_documents', 'ocr', 1, documentId: (int) $document->id, unitId: (int) $unit->id,
            processingLineageId: $lineage, creditOperationId: $id);
        app(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface::class)
            ->recognize(new \App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput(str_repeat('fixture-image', 12000), 'image/png', 'room.png', 1, $context));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        self::assertSame(1, DB::table('estimate_generation_credit_attempts')->where('operation_id', $id)->count());
        $unit->update(['status' => 'completed', 'claim_token' => null, 'lease_expires_at' => null,
            'output_version' => hash('sha256', 'facts-only-output'), 'output_count' => 1, 'completed_at' => now()]);
        self::assertTrue($complete->handle($started->fresh()));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertSame('settled', $operation->status);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertSame(1, DB::table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $operation->reservation_id)->count());
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame('input_review_required', $session->fresh()->status->value);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
        self::assertTrue($complete->handle($session->fresh()));
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $organization->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentCompletionDenials')]
    public function test_document_results_do_not_publish_or_charge_after_access_changes(string $change): void
    {
        $this->seedGlobalSettings();
        [$organization, $actor, $request] = $this->fixture();
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        \Illuminate\Support\Facades\Queue::fake();
        $session = EstimateGenerationSession::query()->findOrFail($request->sessionId);
        $source = 'sha256:'.hash('sha256', 'result-denial-'.$change);
        $lineage = (string) Str::uuid();
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $actor->id,
            'filename' => 'room.png', 'mime_type' => 'image/png', 'storage_path' => 'fixture/private/room.png',
            'checksum_sha256' => substr($source, 7), 'source_version' => $source, 'page_count' => 1, 'status' => 'queued',
            'processing_control_status' => 'active', 'processing_control_source_version' => $source,
            'processing_control_attempt_id' => $lineage, 'meta' => ['processing_attempt_id' => $lineage]]);
        $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $organization->id, 'project_id' => $session->project_id, 'session_id' => $session->id,
            'document_id' => $document->id, 'unit_type' => 'raster_image', 'unit_index' => 1, 'source_version' => $source, 'status' => 'pending',
            'locator' => ['source_kind' => 'image', 'source_version' => $source, 'coordinate_space' => 'image_pixels',
                'artifact_path' => 'fixture/private/room.png', 'artifact_sha256' => 'sha256:'.hash('sha256', 'room-artifact'),
                'artifact_version_id' => 'fixture.v1', 'content_type' => 'image/png'], 'metadata' => ['processing_attempt_id' => $lineage]]);
        app(AICreditService::class)->grant($organization, 1000000, 'purchase', null, 'test:document-result-'.$change);
        $id = (string) Str::uuid();
        $quote = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class)
            ->quote($actor, (int) $session->project_id, (int) $session->id, 0, $id, 'documents');
        $started = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\StartEstimatorDocumentOperation::class)
            ->handle($actor, (int) $session->project_id, (int) $session->id, ['request_id' => $id, 'quote_id' => $quote['quote_id'], 'state_version' => 0]);
        $token = (string) Str::uuid();
        $unit->update(['status' => 'running', 'claim_token' => $token, 'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(5), 'started_at' => now()]);
        config(['estimate-generation.universal_enabled' => false, 'estimate-generation.credits.enabled' => false]);
        match ($change) {
            'actor' => $actor->update(['is_active' => false]),
            'membership' => $actor->organizations()->updateExistingPivot($organization->id, ['is_active' => false]),
            'product' => $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class,
                \Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class)->allows('hasModuleAccess')->andReturn(false)->getMock()),
            'rollout' => $started->update(['input_payload' => [...$started->input_payload, 'evaluation_mode' => 'universal']]),
            'cancel' => app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
                ->handle($started, $started->state_version, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::Cancelled, $actor),
        };
        $published = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class)->publish(
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaim((int) $unit->id,
                \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus::Acquired, $token,
                organizationId: (int) $organization->id, projectId: (int) $session->project_id, sessionId: (int) $session->id,
                documentId: (int) $document->id, sourceVersion: $source),
            new \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput(hash('sha256', 'late-output'), 'Never publish this'),
            now()->toDateTimeImmutable());
        self::assertFalse($published);
        self::assertTrue(app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\CompleteEstimatorDocumentOperation::class)->handle($started->fresh()));
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $id)->sole();
        self::assertContains($operation->status, ['failed', 'cancelled']);
        self::assertSame(0, (int) $operation->consumed_units_minor);
        self::assertNull($unit->fresh()->output_version);
        self::assertSame(1000000, app(AICreditService::class)->balance($organization)['available_minor']);
        self::assertSame($change === 'cancel' ? 'cancelled' : 'processing_documents', $session->fresh()->status->value);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class);
    }

    public static function documentCompletionDenials(): array
    {
        return array_map(static fn (string $change): array => [$change], ['actor', 'membership', 'product', 'rollout', 'cancel']);
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
