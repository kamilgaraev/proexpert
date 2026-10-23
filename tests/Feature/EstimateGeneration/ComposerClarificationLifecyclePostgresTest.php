<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationQuestionController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PublishValidatedDraft;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\BuildDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;

final class ComposerClarificationLifecyclePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_local_rebuild_captures_only_its_current_work_answer_and_forwards_the_manifest_scope(): void
    {
        [$actor, $session, $project] = $this->publish('universal', true, true, true);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $controller = app(EstimateGenerationQuestionController::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $cards = $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'];
        self::assertCount(2, $cards);
        foreach ($cards as $index => $card) {
            $code = $card['code'];
            $card = array_values(array_filter($controller->index($request, $project, $session->fresh())->getData(true)['data']['items'],
                static fn (array $current): bool => $current['code'] === $code))[0];
            $result = $controller->answer($this->answerRequest($actor, $project, ['response' => 'other',
                'other' => 'Допущение для работы '.$index, 'idempotency_key' => 'local-work-answer-'.$index,
                'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint']]),
                $project, $session->fresh(), $card['code']);
            self::assertSame(200, $result->getStatusCode(), $result->getContent());
        }
        $session->refresh();
        $key = 'ai-composer-supplementary:equipment';
        $captured = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
            $session->input_payload['selected_sections'], 'universal', $session->input_payload, $key]);
        self::assertCount(1, $captured->workTechnologyContext);
        self::assertSame('custom_equipment_installation', $captured->workTechnologyContext[0]['context']['binding']['work_key']);
        self::assertNotNull($captured->workTechnologyContext[0]['answer']);
        $session->update(['status' => 'generating', 'input_payload' => [...$session->input_payload, 'rebuild_section_key' => $key]]);
        $model = $this->createMock(EstimateComposerModel::class);
        $model->expects(self::once())->method('compose')->willReturnCallback(static function (EstimateComposerInput $input, callable $reserved): array {
            self::assertCount(1, $input->canonicalPayload()['work_technology_context']);
            self::assertSame('custom_equipment_installation', $input->workTechnologyContext[0]['context']['binding']['work_key']);
            self::assertNotNull($input->workTechnologyContext[0]['answer']);
            $reserved((string) Str::uuid());

            return ['work_intents' => []];
        });
        $this->plan($session->fresh(), $model);
    }

    public function test_current_work_question_is_readable_but_not_writable_outside_review(): void
    {
        [$actor, $session, $project] = $this->publish('universal', true);
        $controller = app(EstimateGenerationQuestionController::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        foreach (['generating', 'ready_to_apply'] as $status) {
            $session->update(['status' => $status]);
            $cards = $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'];
            self::assertCount(1, $cards);
            self::assertSame([], $cards[0]['actions']);
            $input = ['response' => 'other', 'other' => 'Допущение пользователя.', 'idempotency_key' => 'work-read-only-'.$status,
                'expected_source_version' => $cards[0]['source_version'], 'answer_fingerprint' => $cards[0]['answer_fingerprint']];
            self::assertSame(409, $controller->answer($this->answerRequest($actor, $project, $input), $project,
                $session->fresh(), $cards[0]['code'])->getStatusCode());
        }
        $session->update(['status' => 'estimate_review_required']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturn(false);
        $this->app->instance(AuthorizationService::class, $authorization);
        self::assertSame([], $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'][0]['actions']);
    }

    public function test_excluded_work_loses_current_question_and_composer_context_without_erasing_history(): void
    {
        [$actor, $session, $project] = $this->publish('universal', true, true);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $controller = app(EstimateGenerationQuestionController::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $cards = $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'];
        self::assertCount(2, $cards);
        $excluded = array_values(array_filter($cards, static fn (array $card): bool => $card['source_locator']['work_key'] === 'custom_equipment_second'))[0];
        $input = ['response' => 'other', 'other' => 'Допущение для исключаемой работы.', 'idempotency_key' => 'work-before-exclusion',
            'expected_source_version' => $excluded['source_version'], 'answer_fingerprint' => $excluded['answer_fingerprint']];
        self::assertSame(200, $controller->answer($this->answerRequest($actor, $project, $input), $project, $session->fresh(), $excluded['code'])->getStatusCode());
        $session->refresh();
        $items = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope)->items($session->draft_payload);
        $selected = array_keys(array_filter($items, static fn (array $item): bool => $item['binding']['work_key'] === 'custom_equipment_installation'));
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection::class)->handle($actor, $project->id, $session->id,
            ['request_id' => (string) Str::uuid(), 'state_version' => $session->state_version, 'selected_sections' => $session->input_payload['selected_sections'],
                'profile_id' => 'construction', 'construction_type' => 'new_construction', 'price_policy' => 'normative',
                'artifact_hash' => $session->draft_payload['artifact_hash'], 'selected_work_item_keys' => $selected,
                'scope_reason' => 'Для текущей оценки выбрана только первая работа.']);
        self::assertCount(1, $controller->index($request, $project, $session->fresh())->getData(true)['data']['items']);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation::class)->handle($actor,
            $project->id, $session->id, $session->fresh()->state_version, (string) Str::uuid());
        $current = $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'];
        self::assertCount(1, $current);
        self::assertSame('custom_equipment_installation', $current[0]['source_locator']['work_key']);
        $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
            $session->input_payload['selected_sections'], 'universal', $session->fresh()->input_payload]);
        self::assertCount(1, $capture->workTechnologyContext);
        self::assertSame('custom_equipment_installation', $capture->workTechnologyContext[0]['context']['binding']['work_key']);
        $input['idempotency_key'] = 'work-after-exclusion';
        self::assertSame(409, $controller->answer($this->answerRequest($actor, $project, $input), $project, $session->fresh(), $excluded['code'])->getStatusCode());
        $history = array_filter(app(ProjectModelRepository::class)->snapshot(...$scope)->facts,
            static fn ($fact): bool => is_array($fact->value) && ($fact->value['question_key'] ?? null) === $excluded['code']);
        self::assertCount(1, $history);
    }

    public function test_current_work_question_basis_uses_only_current_scoped_sources_in_read_states(): void
    {
        [$actor, $session, $project] = $this->publish('universal', true);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $catalog = app(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class);
        $card = $catalog->allCurrent(...$scope)[0];
        $basis = app(\App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\AnalysisBasisPayloadService::class);
        foreach (['estimate_review_required', 'generating', 'ready_to_apply'] as $status) {
            $session->update(['status' => $status]);
            $payload = $basis->handle(...[...$scope, 'question', $card->question->code]);
            self::assertSame($card->question->reason, $payload['explanation'] ?? null);
            self::assertSame($card->question->impact, $payload['impact'] ?? null);
            self::assertNotEmpty($payload['sources']);
            self::assertArrayNotHasKey('answer', $payload);
        }
        self::assertNull($basis->handle($scope[0], $scope[1] + 1, $scope[2], 'question', $card->question->code));
        self::assertNull($basis->handle(...[...$scope, 'question', 'composer_work_technology_'.str_repeat('f', 40)]));
    }

    public function test_work_technology_answers_are_append_only_project_context_with_current_document_pins(): void
    {
        [$actor, $session, $project] = $this->publish('universal', true);
        $scope = [(int) $session->organization_id, (int) $project->id, (int) $session->id];
        $controller = app(EstimateGenerationQuestionController::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $cards = fn (): array => $controller->index($request, $project, $session->fresh())->getData(true)['data']['items'];
        $actualCards = $cards();
        self::assertCount(1, $actualCards);
        $card = $actualCards[0];
        self::assertSame('composer_work_technology', $card['source_locator']['kind']);
        self::assertSame(['answer'], $card['actions']);
        $models = app(ProjectModelRepository::class);
        $originals = array_column($models->snapshot(...$scope)->facts, null, 'id');
        $document = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument::query()->create([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2], 'user_id' => $actor->id,
            'filename' => 'Инструкция.pdf', 'mime_type' => 'application/pdf', 'storage_path' => 'private/instruction.pdf',
            'status' => 'ready', 'checksum_sha256' => str_repeat('c', 64), 'source_version' => 'sha256:'.str_repeat('c', 64)]);
        $previous = null;
        foreach (['other', 'other', 'revoke', 'leave_unresolved', 'other'] as $index => $response) {
            $card = $cards()[0];
            $input = ['response' => $response, 'idempotency_key' => 'work-technology-answer-000'.$index,
                'expected_source_version' => $card['source_version'], 'answer_fingerprint' => $card['answer_fingerprint'],
                ...($response === 'other' ? ['other' => 'Способ монтажа выбран пользователем для текущего проекта.', 'document_ids' => [(int) $document->id]] : [])];
            $result = $controller->answer($this->answerRequest($actor, $project, $input), $project, $session->fresh(), $card['code']);
            self::assertSame(200, $result->getStatusCode(), $result->getContent());
            self::assertSame($result->getData(true), $controller->answer($this->answerRequest($actor, $project, $input), $project,
                $session->fresh(), $card['code'])->getData(true));
            $current = $cards()[0];
            self::assertSame($card['code'], $current['code']);
            self::assertSame($response, $current['answer']['response']);
            self::assertSame($response === 'other' ? 'user_assumption' : 'no_active_claim', $current['answer']['trust']);
            self::assertFalse($current['answer']['numeric_parameters_confirmed']);
            self::assertSame('unresolved', $current['answer']['engineering_applicability']);
            $fact = $models->fact(...[...$scope, $current['answer']['fact_id']]);
            self::assertSame($previous, $fact->supersedesFactId);
            self::assertSame($index + 1, $fact->version);
            self::assertSame([], $fact->nativeNumericProofs);
            $previous = $fact->id;
            $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
                $session->input_payload['selected_sections'], 'universal', $session->input_payload]);
            self::assertSame($current['answer'], $capture->canonicalPayload()['work_technology_context'][0]['answer']);
            self::assertCount($response === 'other' ? 1 : 0, $capture->canonicalPayload()['work_technology_context'][0]['documents']);
            if ($response === 'other') {
                self::assertSame('sha256:'.str_repeat('c', 64), $capture->canonicalPayload()['work_technology_context'][0]['documents'][0]['source_version']);
            }
            self::assertNotContains($fact->id, array_column($capture->facts, 'id'));
            self::assertNotContains($fact->id, $capture->claimAnswerFactIds);
            foreach ($originals as $id => $original) {
                self::assertSame($original->value, $models->fact(...[...$scope, $id])->value);
            }
        }
        $document->update(['ignored_at' => now(), 'status' => 'ignored']);
        $invalidated = $cards()[0];
        self::assertNull($invalidated['answer']);
        $capture = app(EstimateComposerInputFactory::class)->capture(...[...$scope, [], [], [], null,
            $session->input_payload['selected_sections'], 'universal', $session->input_payload]);
        self::assertNull($capture->workTechnologyContext[0]['answer']);
        $afterDrift = ['response' => 'other', 'other' => 'Новое допущение без отозванного документа.', 'document_ids' => [],
            'idempotency_key' => 'work-technology-answer-after-drift', 'expected_source_version' => $invalidated['source_version'],
            'answer_fingerprint' => $invalidated['answer_fingerprint']];
        $updated = $controller->answer($this->answerRequest($actor, $project, $afterDrift), $project, $session->fresh(), $invalidated['code']);
        self::assertSame(200, $updated->getStatusCode(), $updated->getContent());
        $after = $cards()[0];
        self::assertSame([], $after['answer']['document_ids']);
        self::assertSame($previous, $models->fact(...[...$scope, $after['answer']['fact_id']])->supersedesFactId);
        self::assertSame(6, $models->fact(...[...$scope, $after['answer']['fact_id']])->version);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    #[DataProvider('modes')]
    public function test_published_null_result_has_one_answerable_card_and_preserves_the_original_request(string $mode): void
    {
        [$actor, $session, $project] = $this->publish($mode);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $original = $models->snapshot(...$scope)->facts[0];
        $evaluation = app(GetCurrentEvaluation::class)->handle($actor, $scope[1], $scope[2]);
        self::assertNull($evaluation['result']);
        self::assertCount(2, $evaluation['request_info']);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $catalog = app(EstimateGenerationQuestionController::class)->index($request, $project, $session);
        self::assertSame(200, $catalog->getStatusCode());
        $items = $catalog->getData(true)['data']['items'];
        self::assertCount(1, $items);
        $card = $items[0];
        self::assertSame(2, $card['source_locator']['request_count']);
        self::assertSame(['other', 'leave_unresolved'], array_column($card['choices'], 'kind'));
        self::assertArrayNotHasKey('composer_context', $card);
        $answerData = ['response' => 'other', 'other' => 'Требуется только монтаж стендов; размеры и спецификацию предоставлю отдельно.',
            'idempotency_key' => 'composer-answer-0001', 'expected_source_version' => $card['source_version'],
            'answer_fingerprint' => $card['answer_fingerprint']];
        $controller = app(EstimateGenerationQuestionController::class);
        foreach (['answer_fingerprint' => str_repeat('f', 64), 'expected_source_version' => 'sha256:'.str_repeat('f', 64)] as $field => $value) {
            self::assertSame(409, $controller->answer($this->answerRequest($actor, $project, [...$answerData, $field => $value]), $project, $session, $card['code'])->getStatusCode());
        }
        $draft = $session->draft_payload;
        $session->forceFill(['draft_payload' => [...$draft, 'title' => 'Изменён после подписи']])->save();
        self::assertSame(409, $controller->answer($this->answerRequest($actor, $project, $answerData), $project, $session, $card['code'])->getStatusCode());
        $session->forceFill(['draft_payload' => $draft, 'state_version' => (int) $session->state_version + 1])->save();
        self::assertSame(409, $controller->answer($this->answerRequest($actor, $project, $answerData), $project, $session, $card['code'])->getStatusCode());
        $session->forceFill(['state_version' => (int) $session->state_version - 1])->save();
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id, 'is_active' => true]);
        self::assertSame(403, $controller->answer($this->answerRequest($foreign, $project, $answerData), $project, $session, $card['code'])->getStatusCode());
        $entitlements = Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);
        $entitlements->allows('hasModuleAccess')->andReturn(true);
        $this->app->instance(\App\Services\Entitlements\OrganizationEntitlementService::class, $entitlements);
        config(['ai-assistant-credits.enforce' => false, 'estimate-generation.credits.enabled' => true]);
        app(\App\Services\Credits\AICreditService::class)->grant(Organization::query()->findOrFail($scope[0]), 100000000, 'purchase', null, 'test:composer-answer');
        $credits = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations::class);
        $oldOperation = (string) Str::uuid();
        $oldQuote = $credits->quote($actor, $scope[1], $scope[2], (int) $session->state_version, $oldOperation, 'generation');
        $answer = $this->answerRequest($actor, $project, [...$answerData,
            'composer_context' => ['verified' => true], 'source_locator' => ['artifact_hash' => 'forged']]);
        $response = app(EstimateGenerationQuestionController::class)->answer($answer, $project, $session, $card['code']);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('answered', $response->getData(true)['data']['status']);
        $snapshot = $models->snapshot(...$scope);
        $answers = array_values(array_filter($snapshot->facts, static fn ($fact): bool => is_array($fact->value) && ($fact->value['kind'] ?? null) === 'composer_answer'));
        self::assertCount(1, $answers);
        self::assertSame('user_assumption', $answers[0]->origin);
        self::assertFalse($answers[0]->value['numeric_parameters_confirmed']);
        self::assertNull($answers[0]->unit);
        self::assertNull($answers[0]->supersedesFactId);
        self::assertSame([], $answers[0]->nativeNumericProofs);
        self::assertSame($original->value, $models->fact(...[...$scope, $original->id])->value);
        self::assertContains($original->id, array_column($snapshot->facts, 'id'));
        self::assertSame($original->evidenceIds, $models->fact(...[...$scope, $original->id])->evidenceIds);
        self::assertNotSame($original->id, $answers[0]->id);
        self::assertSame($card['source_locator'], $answers[0]->value['source_locator']);
        self::assertArrayNotHasKey('verified', $answers[0]->value['composer_context']);
        $captured = (new EstimateComposerInputFactory($models, 10000))->capture(...[...$scope, [], [], [], null,
            $mode === 'universal' ? $session->input_payload['selected_sections'] : [], $mode, $session->fresh()->input_payload]);
        self::assertCount(1, array_filter($captured->facts, static fn (array $fact): bool => ($fact['context_kind'] ?? null) === 'composer_answer'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_evaluation_revisions')->where('session_id', $session->id)->count());
        $replay = app(EstimateGenerationQuestionController::class)->answer($answer, $project, $session, $card['code']);
        self::assertSame($response->getData(true), $replay->getData(true));
        $this->assertReplayAfterWaitingForSessionLock($actor, $session, $card, $answers[0], $models);
        self::assertSame(422, $controller->answer($this->answerRequest($actor, $project, [...$answerData, 'other' => 'Другой ответ']), $project, $session, $card['code'])->getStatusCode());
        self::assertCount(1, array_filter($models->snapshot(...$scope)->facts, static fn ($fact): bool => is_array($fact->value) && ($fact->value['kind'] ?? null) === 'composer_answer'));
        try {
            DB::transaction(fn () => $credits->begin($actor, $session->fresh(), (int) $session->state_version, $oldOperation, $oldQuote['quote_id'], 'generation'));
            self::fail('The answer reused the previous approved context.');
        } catch (\DomainException $exception) {
            self::assertSame('estimator_known_context_changed', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $scope[0])->count());
        $session = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession::class)
            ->handle($session->fresh(), (int) $session->state_version,
                \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::InputConfirmed, $actor);
        $operation = (string) Str::uuid();
        $quote = $credits->quote($actor, $scope[1], $scope[2], (int) $session->state_version, $operation, 'generation');
        $version = (int) $session->state_version;
        $approval = ['request_id' => $operation, 'quote_id' => $quote['quote_id']];
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)
            ->handle($session, $version, 'ai_assisted', $actor, creditApproval: $approval);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration::class)
            ->handle($session, $version, 'ai_assisted', $actor, creditApproval: $approval);
        Queue::assertPushed(\App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class, 1);
        self::assertSame(1, DB::table('ai_credit_reservations')->where('organization_id', $scope[0])->count());
        $nextModel = $this->createMock(EstimateComposerModel::class);
        $nextModel->expects(self::once())->method('compose')->willReturnCallback(static function (EstimateComposerInput $input, callable $reserved) use ($answers): array {
            $answerFact = array_column($input->facts, null, 'id')[$answers[0]->id];
            self::assertSame('composer_answer', $answerFact['context_kind']);
            self::assertSame($answers[0]->value['other'], $answerFact['value']['answer']);
            self::assertFalse($answerFact['value']['numeric_parameters_confirmed']);
            $reserved((string) Str::uuid());

            return ['work_intents' => [[
                'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => 'custom.request', 'name' => 'Монтаж стендов',
                'work_section' => 'equipment', 'unit' => 'шт', 'derived_quantity_id' => null, 'source_fact_ids' => [$answers[0]->id],
                'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
            ]], ...($input->acceptsSourceClaims() ? ['claim_candidates' => []] : [])];
        });
        [$next, $results, $payloads] = $this->plan($session->fresh(), $nextModel);
        self::assertCount(1, $next['local_estimates']);
        $work = $next['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('Монтаж стендов', $work['name']);
        self::assertNull($work['quantity'] ?? null);
        self::assertNull($work['unit']);
        self::assertNull($work['unit_price'] ?? null);
        self::assertNull($work['total_cost']);
        self::assertArrayNotHasKey('quantity_evidence', $work);
        $published = $this->publishPlan($session->fresh(), $next, $results, $payloads);
        self::assertFalse($published->draft_payload['source_info_only'] ?? false);
        $after = (new EstimateComposerInputFactory($models, 10000))->capture(...[...$scope, [], [], [], null,
            $mode === 'universal' ? $session->input_payload['selected_sections'] : [], $mode, $published->input_payload]);
        self::assertCount(1, array_filter($after->facts, static fn (array $fact): bool => ($fact['context_kind'] ?? null) === 'composer_answer'));
        $newVersion = 'sha256:'.str_repeat('b', 64);
        $independentEntity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:independent', ...[...$scope, $newVersion,
            'room', 'room:independent', ['semantic_type' => 'room', 'identity' => ['room_name' => 'Other room']]]);
        $independentFact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:independent', ...[...$scope, $newVersion,
            $independentEntity->id, 'room_name', 'Другая комната', null, 0.0, 'user_assumption', 'confirmed', []]);
        $models->saveSourceModel([$independentEntity], [$independentFact], []);
        $factory = new EstimateComposerInputFactory($models, 10000);
        $arguments = [...$scope, [], [], [], null, $mode === 'universal' ? $session->input_payload['selected_sections'] : [], $mode, $published->input_payload];
        self::assertCount(1, array_filter($factory->capture(...$arguments)->facts, static fn (array $fact): bool => ($fact['context_kind'] ?? null) === 'composer_answer'));
        $models->invalidateSourceVersion(...[...$scope, $original->sourceVersion, $newVersion]);
        self::assertCount(0, array_filter($factory->capture(...$arguments)->facts, static fn (array $fact): bool => ($fact['context_kind'] ?? null) === 'composer_answer'));
        Http::assertNothingSent();
    }

    public function test_leave_unresolved_is_saved_separately_without_admitting_answer_context_or_model_calls(): void
    {
        [$actor, $session, $project] = $this->publish('legacy');
        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $controller = app(EstimateGenerationQuestionController::class);
        $card = $controller->index($request, $project, $session)->getData(true)['data']['items'][0];
        $response = $controller->answer($this->answerRequest($actor, $project, ['response' => 'leave_unresolved',
            'idempotency_key' => 'composer-unresolved-0001', 'expected_source_version' => $card['source_version'],
            'answer_fingerprint' => $card['answer_fingerprint']]), $project, $session, $card['code']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('left_unresolved', $response->getData(true)['data']['status']);
        $models = app(ProjectModelRepository::class);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $saved = array_values(array_filter($models->snapshot(...$scope)->facts,
            static fn ($fact): bool => is_array($fact->value) && ($fact->value['kind'] ?? null) === 'composer_answer'));
        self::assertCount(1, $saved);
        self::assertSame('unresolved', $saved[0]->status);
        self::assertSame('user_assumption', $saved[0]->origin);
        $input = (new EstimateComposerInputFactory($models, 10000))->capture(...[...$scope, [], [], [], null, [], 'legacy', $session->fresh()->input_payload]);
        self::assertNotContains($saved[0]->id, array_column($input->facts, 'id'));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }

    public static function modes(): array
    {
        return [['legacy'], ['universal']];
    }

    private function publish(string $mode, bool $workQuestion = false, bool $secondWork = false, bool $separateEstimate = false): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        Http::fake();
        Http::preventStrayRequests();
        Queue::fake();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $input = ['evaluation_mode' => $mode, 'building_type' => 'bridge', 'profile_id' => 'construction', 'generation_mode' => 'ai_assisted',
            'selected_sections' => $mode === 'universal' ? ($workQuestion ? ($separateEstimate ? ['equipment', 'heating'] : ['equipment']) : ['heating', 'roofing', 'equipment']) : [],
            'description' => 'Монтаж неизвестной установки и покрытия'];
        $session = app(CreateEstimateGenerationSession::class)->handle(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'input_payload' => $input], $actor);
        $inputHash = app(EvaluationInputFingerprint::class)->fromSession($session);
        $session->forceFill(['status' => 'generating', 'state_version' => 4, 'input_payload' => [...$input,
            'generation_actor_type' => 'user', 'generation_actor_id' => $actor->id, 'generation_attempt_id' => (string) Str::uuid(),
            'generation_evaluation_input_hash' => $inputHash]])->save();
        $source = app(ProjectModelRepository::class)->snapshot((int) $organization->id, (int) $project->id, (int) $session->id)->facts[0];
        $model = $this->createMock(EstimateComposerModel::class);
        $model->expects(self::once())->method('compose')->willReturnCallback(static function (EstimateComposerInput $captured, callable $reserved) use ($source, $workQuestion, $secondWork, $separateEstimate): array {
            $reserved((string) Str::uuid());
            if ($workQuestion) {
                $intent = ['kind' => 'supplementary', 'work_key' => 'custom_equipment_installation',
                    'name' => 'Монтаж неизвестной установки', 'work_section' => 'equipment', 'unit' => 'count', 'derived_quantity_id' => null,
                    'source_fact_ids' => [$source->id], 'entity_id' => $source->entityId, 'technology_package_candidate' => null,
                    'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
                    'technology_clarification' => ['question' => 'Как выполняется крепление этой установки?', 'reason' => 'В исходных данных не указан способ монтажа.']];

                return ['work_intents' => [$intent, ...($secondWork ? [[...$intent, 'work_key' => 'custom_equipment_second', 'name' => 'Монтаж второй неизвестной установки',
                    'work_section' => $separateEstimate ? 'heating' : 'equipment']] : [])]];
            }

            return ['work_intents' => array_map(static fn (array $work): array => [
                'kind' => 'supplementary', 'candidate_id' => null, 'work_key' => $work[0], 'name' => $work[1],
                'work_section' => $work[2], 'unit' => $work[3], 'derived_quantity_id' => null, 'source_fact_ids' => [$source->id],
                'technology_package_candidate' => null, 'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => [],
            ], [['heating.unit', 'Монтаж неизвестной установки', 'heating', 'count'], ['roof.area', 'Монтаж неизвестного покрытия', 'roofing', 'm2']])];
        });
        [$plan, $results, $payloads] = $this->plan($session, $model);
        self::assertCount($workQuestion ? 0 : 2, $plan['estimate_composition']['request_info']);
        $published = $this->publishPlan($session, $plan, $results, $payloads);
        self::assertSame($workQuestion ? 'estimate_review_required' : 'input_review_required', $published->status->value);

        return [$actor, $published, $project];
    }

    private function publishPlan(EstimateGenerationSession $session, array $plan, StageResultFactory $results, array $payloads): EstimateGenerationSession
    {
        $payloads[ProcessingStage::PlanWorkItems->value] = $plan;
        $payloads[ProcessingStage::ResolvePrices->value] = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => $plan['local_estimates']];
        $payloads[ProcessingStage::BuildDraft->value] = ['draft' => (new BuildDraftStage($results,
            quantityEvidence: app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier::class)))
            ->execute($this->context($session, ProcessingStage::BuildDraft, $payloads, $results))->transientData['draft']];
        $context = $this->context($session, ProcessingStage::ValidateDraft, $payloads, $results);
        $auditModel = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel::class);
        $auditModel->expects($session->input_payload['evaluation_mode'] === 'universal' && $plan['local_estimates'] !== [] ? self::once() : self::never())
            ->method('audit')->willReturnCallback(static function ($input, callable $reserved): array {
                $reserved((string) Str::uuid());

                return ['findings' => []];
            });
        $audits = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle(
            new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit(new InMemoryAiRoleRunRepository, $auditModel, 'openai/gpt-6-luna'),
            app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection::class));
        $validated = (new ValidateDraftStage(app(\App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService::class),
            new \App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector, $results,
            $audits, app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory::class),
            models: app(ProjectModelRepository::class)))->execute($context);
        $notifications = $this->createMock(EstimateGenerationNotificationService::class);
        $notifications->method('notifyFinished')->willReturn(true);
        $this->app->instance(EstimateGenerationNotificationService::class, $notifications);
        DB::transaction(fn () => app(PublishValidatedDraft::class)->beforeComplete(
            CheckpointClaim::acquired($context, ProcessingStage::ValidateDraft, (string) Str::uuid()), $validated, new DateTimeImmutable));
        return $session->fresh();
    }

    private function plan(EstimateGenerationSession $session, EstimateComposerModel $model): array
    {
        $input = $session->input_payload;
        $mode = $input['evaluation_mode'];
        $inputHash = $input['generation_evaluation_input_hash'];
        $results = new StageResultFactory(new InMemoryPipelineArtifactStore, PipelineDefinitionGraph::standard());
        $composer = new RunEstimateComposer(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-6-luna',
            allowance: app(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerAllowance::class));
        $analysis = (new ConstructionSemanticParser)->parse($input, []);
        $analysis['evaluation_policy'] = ['mode' => $mode, 'price_policy' => 'normative', 'profile_id' => 'construction', 'selected_sections' => $input['selected_sections']];
        $payloads = [ProcessingStage::UnderstandDocuments->value => ['base_input_version' => 'sha256:'.$inputHash,
                'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => $input['rebuild_section_key'] ?? null],
            ProcessingStage::UnderstandObject->value => ['analysis' => $analysis],
            ProcessingStage::ExtractQuantities->value => ['quantity_learning_hints' => [], 'building_quantities' => [],
                'quantity_coverage_warnings' => [], 'stage6_generation_context' => []]];
        $planner = new PlanWorkItemsStage(app(WorkPlanCompiler::class), $results, app(AcceptedQuantityEvidenceMaterializer::class),
            $composer, new EstimateComposerInputFactory(app(ProjectModelRepository::class), 10000,
                clarifications: app(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog::class)));
        $plan = $planner->execute($this->context($session, ProcessingStage::PlanWorkItems, $payloads, $results))->transientData;
        return [$plan, $results, $payloads];
    }

    private function answerRequest(User $actor, Project $project, array $input): AnswerEstimateClarificationRequest
    {
        $request = AnswerEstimateClarificationRequest::create('/', 'POST', $input);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $actor);
        $route = new Route('POST', 'projects/{project}/questions/{question}/answer', []);
        $route->bind($request);
        $route->setParameter('project', $project);
        $request->setRouteResolver(static fn (): Route => $route);
        $request->validateResolved();

        return $request;
    }

    private function assertReplayAfterWaitingForSessionLock(User $actor, EstimateGenerationSession $session, array $card,
        \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact $answer, ProjectModelRepository $models): void
    {
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $saved = $models->decisions(...[...$scope, [$answer->value['decision_id']]]);
        $interleaved = $this->createMock(ProjectModelRepository::class);
        $interleaved->expects(self::exactly(2))->method('decisions')->willReturnOnConsecutiveCalls([], $saved);
        $interleaved->expects(self::once())->method('fact')->willReturn($answer);
        $source = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationSource::class);
        $source->expects(self::never())->method('findCurrent');
        $planning = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger::class);
        $planning->expects(self::once())->method('trigger');
        $handler = new \App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification(
            app(AuthorizationService::class), $source, $interleaved,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision($interleaved), $planning);
        $replayed = $handler->handle($actor, $session,
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext(...[...array_slice($scope, 0, 2),
                (int) $actor->id, 'composer-answer-0001', $card['source_version'], $card['answer_fingerprint']]),
            $card['code'], 'other', $answer->value['other']);
        self::assertSame($answer->value['decision_id'], $replayed->decisionId);
    }

    private function context(EstimateGenerationSession $session, ProcessingStage $stage, array $payloads, StageResultFactory $results): PipelineContext
    {
        $base = 'sha256:'.$session->input_payload['generation_evaluation_input_hash'];
        $graph = PipelineDefinitionGraph::standard();
        $outputs = [];
        foreach ($payloads as $key => $payload) {
            $prior = ProcessingStage::from($key);
            $versions = array_fill_keys(array_map(static fn (ProcessingStage $dependency): string => $dependency->value, $graph->get($prior)->dependencies), $base);
            $outputs[$key] = $results->make(new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
                (int) $session->state_version, $base, 'generating', generationAttemptId: $session->input_payload['generation_attempt_id'], baseInputVersion: $base,
                stage: $prior, dependencyVersions: $versions), $prior, $payload)->output;
        }
        $versions = [];
        foreach ($graph->get($stage)->dependencies as $dependency) {
            $versions[$dependency->value] = $outputs[$dependency->value]->version;
        }

        return new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id, (int) $session->state_version, $base, 'generating',
            priorOutputs: new PipelinePriorOutputs($outputs, $payloads), generationAttemptId: $session->input_payload['generation_attempt_id'],
            baseInputVersion: $base, stage: $stage, dependencyVersions: $versions,
            creditOperationId: $session->input_payload['generation_credit_operation_id'] ?? null);
    }
}
