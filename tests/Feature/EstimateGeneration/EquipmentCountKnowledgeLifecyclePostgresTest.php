<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklog;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningEvidenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EquipmentCountKnowledgeValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EquipmentCountKnowledgeLifecyclePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[DataProvider('currentAnswerClaims')]
    public function test_native_source_replacement_requires_a_current_answer_and_preserves_the_independent_original(string $proposed, string $outcome): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $writer = app(SaveManualProjectFacts::class);
        $context = $this->nativeContext($session, $actor, 'a');
        $this->publishNative($context);
        $question = app(EstimatePrerequisiteBacklog::class)->current($session->fresh())['native_questions']['questions'][0];
        self::assertSame('equipment_count', $question['answer']['parameter']);
        $answer = $this->countAnswer($question['answer']['entity_id'], $question['answer']['native_question']);
        $result = $writer->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $answer);
        $oldAnswerId = $result['fact_ids'][0];
        $oldFact = $models->factsByIds(...[...$scope, [$oldAnswerId]])[0];
        self::assertSame('user_assumption', $oldFact->origin);
        self::assertSame([], $oldFact->nativeNumericProofs);

        $version = 'sha256:'.str_repeat('b', 64);
        $models->invalidateSourceVersion(...[...$scope, $context->sourceVersion, $version]);
        EstimateGenerationDocument::query()->whereKey($context->documentId)->update([
            'source_version' => $version, 'checksum_sha256' => substr($version, 7),
        ]);
        EstimateGenerationDocumentPage::query()->whereKey($context->pageId)->update(['source_version' => $version]);
        $replacement = new DocumentUnitExecutionContext(1, ...[...$scope, $context->documentId,
            DocumentUnitType::SpreadsheetSheet, 1, $version, [], 'source.xlsx', 'application/octet-stream',
            'source.xlsx', 'fixture-native-replacement', 1, 0, 'draft', $context->pageId]);
        $this->publishNative($replacement, '3');
        $capture = $models->snapshotForPlanning(...[...$scope, 10001]);
        $source = array_values(array_filter($capture['snapshot']->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'))[0];
        self::assertNotSame($oldFact->entityId, $source->entityId);
        self::assertSame('document', $source->origin);
        self::assertSame($version, $source->sourceVersion);
        self::assertNotEmpty($source->nativeNumericProofs);
        self::assertNotContains($oldAnswerId, array_column($capture['snapshot']->facts, 'id'));
        try {
            $writer->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $answer);
            self::fail('The answer bound to the previous document version was accepted.');
        } catch (ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $confirmed = $writer->handle($actor, $scope[1], $scope[2], 1, (string) Str::uuid(), $this->countAnswer($source->entityId, value: $proposed));
        $current = $models->snapshotForPlanning(...[...$scope, 10001]);
        $claim = array_values(array_filter($current['snapshot']->facts, static fn (Fact $fact): bool => in_array($fact->id, $confirmed['fact_ids'], true)))[0];
        self::assertSame('user_assumption', $claim->origin);
        self::assertSame($source->id, $claim->supersedesFactId);
        self::assertNotSame($source->id, $claim->id);
        self::assertSame($version, $claim->sourceVersion);
        $original = $models->factsByIds(...[...$scope, [$claim->supersedesFactId]])[0];
        self::assertSame('document', $original->origin);
        self::assertNotEmpty($original->nativeNumericProofs);

        $quantities = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $quantity = app(WorkItemQuantityMapper::class)->mapForEntity('equipment.install', $quantities, $claim->entityId);
        self::assertNotNull($quantity);
        $item = ['key' => 'equipment:unknown:install', 'name' => 'Монтаж неизвестной установки', 'unit' => 'count',
            'quantity' => $proposed, 'quantity_evidence' => $quantity->toArray(),
            'metadata' => ['quantity_key' => 'equipment.install', 'scope_entity_id' => $claim->entityId]];
        $norm = $this->norm();
        $regional = $this->regionalContext($norm);
        $pin = app(NormativeContextPinResolver::class)->resolve($regional, [[
            'work_item_key' => $item['key'], 'search_text' => $item['name'], 'unit' => $item['unit'], 'code' => $norm->code,
        ]]);
        self::assertSame('review_required', $pin['status']);
        self::assertContains('normative_resource_context_not_approved', $pin['blocking_issues']);
        $draft = ['regional_context' => $regional, 'local_estimates' => [['sections' => [['work_items' => [$item]]]]],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh())];
        $session->fresh()->update(['draft_payload' => app(UniversalReviewSummaryProjector::class)->project($draft)]);
        $recorder = app(EstimateGenerationLearningRecorder::class);
        $resolverLookups = 0;
        DB::listen(static function (QueryExecuted $query) use (&$resolverLookups): void {
            if (str_contains($query->sql, 'select "code" from "estimate_norms"')) {
                $resolverLookups++;
            }
        });
        self::assertSame(1, $recorder->recordUserSelection($session->fresh(), $item, $item, (int) $norm->id, [
            'knowledge_validation' => ['producer' => 'estimate_generation.equipment_count_claim.v1',
                'count_source_check' => ['outcome' => 'passed'], 'normative_association_check' => ['outcome' => 'passed']],
        ]));
        self::assertSame($outcome === 'passed' ? 1 : 0, $resolverLookups);
        $example = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->sole();
        $validation = $example->context_payload['knowledge_validation'] ?? [];
        self::assertSame($outcome, data_get($validation, 'count_source_check.outcome'));
        self::assertSame($claim->id, data_get($validation, 'claim.answer_fact_id'));
        self::assertSame($source->id, data_get($validation, 'claim.source_count_fact_id'));
        self::assertSame('unresolved', data_get($validation, 'normative_association_check.outcome'));
        self::assertSame(0, $recorder->recordUserSelection($session->fresh(), $item, $item, (int) $norm->id, []));
        self::assertSame(1, EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)->count());
        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(collect([$norm]), $item,
            ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]])[$norm->id];
        self::assertSame(0.0, $summary['learning_score']);
        self::assertSame(0, $summary['learning_positive_count']);
        self::assertSame([], $summary['learning_sources']);
        $forged = $example->context_payload;
        $forged['knowledge_validation']['count_source_check']['outcome'] = 'passed';
        $forged['knowledge_validation']['normative_association_check']['outcome'] = 'passed';
        $example->update(['context_payload' => $forged]);
        $validator = app(EquipmentCountKnowledgeValidationService::class);
        self::assertSame($outcome === 'passed', $validator->permitsSourceReuse($example->fresh()));
        $readQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$readQueries): void {
            $readQueries++;
        });
        $lookupsBeforeRead = $resolverLookups;
        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(collect([$norm]), $item,
            ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]])[$norm->id];
        $singleClaimQueries = $readQueries;
        self::assertSame($outcome === 'passed' ? 1 : 0, $resolverLookups - $lookupsBeforeRead);
        self::assertSame(0.0, $summary['learning_score']);
        self::assertSame(0, $summary['learning_positive_count']);
        $repeatedClaim = $example->fresh()->replicate();
        $repeatedClaim->source_entity_id = (int) $example->id;
        $repeatedClaim->save();
        $readQueries = 0;
        app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(collect([$norm]), $item,
            ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]]);
        self::assertSame($singleClaimQueries, $readQueries);
        $foreign = $example->replicate();
        $foreign->organization_id = Organization::factory()->create()->id;
        self::assertFalse($validator->permitsSourceReuse($foreign));
        $sourceEvidence = (int) substr($source->evidenceIds[0], strlen('evidence:'));
        app(EvidenceRepository::class)->invalidate(...[...$scope, [$sourceEvidence], 'knowledge_source_retracted']);
        self::assertFalse($validator->permitsSourceReuse($example->fresh()));
        self::assertSame('unresolved', data_get($validator->capture($session->fresh(), $item, (int) $norm->id), 'count_source_check.outcome'));
    }

    public static function currentAnswerClaims(): iterable
    {
        yield 'independent_source_matches' => ['3', 'passed'];
        yield 'independent_source_disagrees' => ['4', 'failed'];
    }

    #[DataProvider('knowledgeValidationLoads')]
    public function test_learning_validation_work_is_bounded_after_cheap_eligibility(int $claimCount, int $normCount, bool $ineligibleFirst, int $sourceLimit, int $associationLimit): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $context = $this->nativeContext($session, $actor, 'c');
        $this->publishNative($context, '3', array_map(static fn (int $index): string => 'equipment.claim'.$index, range(1, $claimCount)));
        $models = app(ProjectModelRepository::class);
        $snapshot = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $originals = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'));
        self::assertCount($claimCount, $originals);
        foreach ($originals as $index => $source) {
            self::assertSame('document', $source->origin);
            app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], $index, (string) Str::uuid(), $this->countAnswer($source->entityId));
        }
        $quantities = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $items = [];
        foreach ($originals as $index => $source) {
            $quantity = app(WorkItemQuantityMapper::class)->mapForEntity('equipment.install', $quantities, $source->entityId);
            self::assertNotNull($quantity);
            $items[] = ['key' => 'equipment:claim'.$index.':install', 'name' => 'Монтаж неизвестной установки', 'unit' => 'count',
                'quantity' => '3', 'quantity_evidence' => $quantity->toArray(),
                'metadata' => ['quantity_key' => 'equipment.install', 'scope_entity_id' => $source->entityId]];
        }
        $norm = $this->norm();
        $norms = collect([$norm]);
        for ($index = 1; $index < $normCount; $index++) {
            $another = $norm->replicate();
            $another->code = '37-01-001-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $another->save();
            $norms->push($another);
        }
        $draft = ['regional_context' => $this->regionalContext($norm),
            'local_estimates' => [['sections' => [['work_items' => $items]]]],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh())];
        $session->fresh()->update(['draft_payload' => app(UniversalReviewSummaryProjector::class)->project($draft)]);
        $recorder = app(EstimateGenerationLearningRecorder::class);
        $first = null;
        foreach (range(0, max($claimCount, $normCount) - 1) as $index) {
            $item = $items[$index % $claimCount];
            $candidate = $norms[$index % $normCount];
            self::assertSame(1, $recorder->recordUserSelection($session->fresh(), $item, $item, (int) $candidate->id, []));
            $example = EstimateGenerationLearningExample::query()->where('generation_session_id', $session->id)
                ->where('estimate_norm_id', $candidate->id)->where('context_payload->work_item_key', $item['key'])->sole();
            self::assertSame('passed', data_get($example->context_payload, 'knowledge_validation.count_source_check.outcome'));
            $payload = $example->context_payload;
            $payload['knowledge_validation']['normative_association_check']['outcome'] = 'passed';
            $example->update(['context_payload' => $payload, 'accepted_at' => now()->addSeconds($index),
                'work_unit' => $ineligibleFirst && $index > 0 ? 'unsupported-history-unit' : 'count']);
            $first ??= $example;
        }
        $legacy = $first->toArray();
        unset($legacy['id'], $legacy['created_at'], $legacy['updated_at'], $legacy['context_payload']['knowledge_validation']);
        $legacy['source_type'] = 'imported_estimate';
        $legacy['source_entity_id'] = (int) $first->id;
        self::assertSame(1, $recorder->record($legacy));

        $sourceIds = array_map(static fn (Fact $fact): int => (int) substr($fact->evidenceIds[0], strlen('evidence:')), $originals);
        $sourceReads = $associationReads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$sourceReads, &$associationReads, $sourceIds, $scope): void {
            if (str_contains($query->sql, 'select * from "estimate_generation_evidence" where "id" = ?')
                && in_array($query->bindings[0] ?? null, $sourceIds, true)
                && array_slice($query->bindings, 1) === $scope) {
                $sourceReads++;
            }
            if (str_contains($query->sql, 'select "code" from "estimate_norms"')) {
                $associationReads++;
            }
        });
        $summaries = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates($norms, $items[0],
            ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]]);
        self::assertSame($sourceLimit, $sourceReads);
        self::assertSame($associationLimit, $associationReads);
        self::assertGreaterThan(0, $summaries[$norm->id]['learning_score']);
        self::assertSame(1, $summaries[$norm->id]['learning_positive_count']);
        self::assertSame(['imported_estimate'], array_column($summaries[$norm->id]['learning_sources'], 'source_type'));
        foreach ($norms->skip(1) as $candidate) {
            self::assertSame(0.0, $summaries[$candidate->id]['learning_score']);
            self::assertSame(0, $summaries[$candidate->id]['learning_positive_count']);
        }
    }

    public static function knowledgeValidationLoads(): iterable
    {
        yield 'distinct_native_claims' => [12, 1, false, 8, 1];
        yield 'one_claim_distinct_norms' => [1, 12, false, 1, 8];
        yield 'incompatible_units_before_current_claim' => [12, 1, true, 1, 1];
    }

    #[DataProvider('confirmationSourceStates')]
    public function test_normative_feedback_rechecks_equipment_count_instead_of_creating_a_legacy_hint(string $sourceState): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $context = $this->nativeContext($session, $actor, 'd');
        $this->publishNative($context, $sourceState === 'missing' ? null : '3');
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $entityId = $snapshot->entities[0]->id;
        app(SaveManualProjectFacts::class)->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), $this->countAnswer($entityId));
        $quantities = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'];
        $quantity = app(WorkItemQuantityMapper::class)->mapForEntity('equipment.install', $quantities, $entityId);
        self::assertNotNull($quantity);
        $item = ['key' => 'equipment:unknown:install', 'name' => 'Монтаж неизвестной установки', 'unit' => 'count',
            'quantity' => '3', 'quantity_evidence' => $quantity->toArray(),
            'metadata' => ['quantity_key' => 'equipment.install', 'scope_entity_id' => $entityId]];
        $norm = $this->norm();
        $draft = ['regional_context' => $this->regionalContext($norm),
            'local_estimates' => [['sections' => [['work_items' => [$item]]]]],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh())];
        $session->fresh()->update(['draft_payload' => app(UniversalReviewSummaryProjector::class)->project($draft)]);
        if ($sourceState === 'retracted') {
            $original = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->type === 'equipment_count'))[0];
            $evidenceId = (int) substr($original->evidenceIds[0], strlen('evidence:'));
            app(EvidenceRepository::class)->invalidate(...[...$scope, [$evidenceId], 'knowledge_source_retracted']);
        }
        $example = $this->confirmNormative($session->fresh(), $item, $norm);
        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(collect([$norm]), $item,
            ['organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2]])[$norm->id];
        self::assertSame(0.0, $summary['learning_score']);
        self::assertSame(0, $summary['learning_positive_count']);
        self::assertSame([], $summary['learning_sources']);
        self::assertSame($sourceState === 'current' ? 'passed' : 'unresolved', data_get($example->context_payload, 'knowledge_validation.count_source_check.outcome'));
        self::assertSame('unresolved', data_get($example->context_payload, 'knowledge_validation.normative_association_check.outcome'));
    }

    public static function confirmationSourceStates(): iterable
    {
        yield 'missing_native_source' => ['missing'];
        yield 'retracted_native_source' => ['retracted'];
        yield 'current_native_source' => ['current'];
    }

    public function test_non_equipment_normative_feedback_keeps_existing_hint_semantics(): void
    {
        [, $session] = $this->fixture();
        $item = ['key' => 'openings:windows', 'name' => 'Монтаж окон', 'unit' => 'count', 'quantity' => '3',
            'metadata' => ['quantity_key' => 'openings.windows']];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]],
            'source_input_version' => 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($session->fresh())];
        $session->update(['draft_payload' => app(UniversalReviewSummaryProjector::class)->project($draft)]);
        $norm = $this->norm();
        $example = $this->confirmNormative($session->fresh(), $item, $norm);
        self::assertArrayNotHasKey('knowledge_validation', $example->context_payload);
        $summary = app(EstimateGenerationLearningEvidenceService::class)->summarizeForCandidates(collect([$norm]), $item,
            ['organization_id' => $session->organization_id, 'project_id' => $session->project_id, 'session_id' => $session->id])[$norm->id];
        self::assertGreaterThan(0, $summary['learning_score']);
        self::assertSame(1, $summary['learning_positive_count']);
    }

    private function confirmNormative(EstimateGenerationSession $session, array $item, EstimateNorm $norm): EstimateGenerationLearningExample
    {
        $feedback = EstimateGenerationFeedback::query()->create(['session_id' => $session->id, 'user_id' => $session->user_id,
            'feedback_type' => 'normative_confirmation', 'work_item_key' => $item['key'],
            'payload' => ['norm_id' => $norm->id, 'normative_code' => $norm->code,
                'knowledge_validation' => ['producer' => 'forged-request', 'count_source_check' => ['outcome' => 'passed'],
                    'normative_association_check' => ['outcome' => 'passed']]],
        ]);
        $recorder = app(EstimateGenerationLearningRecorder::class);
        self::assertSame(1, $recorder->recordFeedbackDecision($session, $feedback));
        self::assertSame(0, $recorder->recordFeedbackDecision($session, $feedback));

        return EstimateGenerationLearningExample::query()->where('source_type', 'manual_review_choice')
            ->where('source_entity_type', 'estimate_generation_feedback')->where('source_entity_id', $feedback->id)->sole();
    }

    public function test_learning_selection_without_package_item_preserves_organization_session_and_work_identity(): void
    {
        [, $first] = $this->fixture();
        [, $foreign] = $this->fixture();
        $sameProject = $first->replicate();
        $sameProject->save();
        $norm = $this->norm();
        $recorder = app(EstimateGenerationLearningRecorder::class);
        $work = ['key' => 'equipment:unknown:install', 'name' => 'Монтаж неизвестной установки', 'unit' => 'count',
            'quantity' => '3', 'metadata' => ['quantity_key' => 'equipment.install']];
        $ids = [];
        $snapshots = [];
        foreach ([$first, $foreign, $sameProject] as $session) {
            self::assertSame(1, $recorder->recordUserSelection($session, $work, $work, (int) $norm->id, []));
            $example = EstimateGenerationLearningExample::query()->where('organization_id', $session->organization_id)
                ->where('generation_session_id', $session->id)->where('norm_code', $norm->code)->sole();
            $ids[] = (int) $example->id;
            self::assertSame(0, $recorder->recordUserSelection($session, $work, $work, (int) $norm->id, []));
            self::assertSame((int) $example->id, (int) $example->fresh()->id);
            $snapshots[(int) $example->id] = $example->fresh()->getAttributes();
        }
        self::assertCount(3, array_unique($ids));
        $another = [...$work, 'key' => 'equipment:other:install'];
        self::assertSame(1, $recorder->recordUserSelection($first, $another, $another, (int) $norm->id, []));
        self::assertSame(0, $recorder->recordUserSelection($first, $another, $another, (int) $norm->id, []));
        self::assertSame(2, EstimateGenerationLearningExample::query()->where('generation_session_id', $first->id)
            ->where('norm_code', $norm->code)->count());
        foreach ($snapshots as $id => $snapshot) {
            self::assertSame($snapshot, EstimateGenerationLearningExample::query()->findOrFail($id)->getAttributes());
        }
        $missingKey = array_diff_key($work, ['key' => true]);
        self::assertSame(0, $recorder->recordUserSelection($first, $missingKey, $missingKey, (int) $norm->id, []));
        $missingSession = $first->replicate();
        $missingSession->id = 999999999;
        self::assertSame(0, $recorder->recordUserSelection($missingSession, $work, $work, (int) $norm->id, []));
        self::assertSame(4, EstimateGenerationLearningExample::query()->where('norm_code', $norm->code)
            ->whereIn('generation_session_id', [$first->id, $foreign->id, $sameProject->id])->count());
    }

    public function test_existing_native_question_records_an_equipment_count_assumption_without_native_document_proof(): void
    {
        [$actor, $session] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $writer = app(SaveManualProjectFacts::class);
        $writer->handle($actor, $scope[1], $scope[2], 0, (string) Str::uuid(), ['entities' => [[
            'key' => 'unknown-equipment', 'name' => 'Неизвестная установка', 'type' => 'equipment', 'parameters' => [],
        ]]]);
        $before = $models->snapshotForPlanning(...[...$scope, 10001]);
        self::assertCount(1, $before['snapshot']->entities);
        self::assertSame([], array_values(array_filter($before['snapshot']->facts,
            static fn (Fact $fact): bool => $fact->type === 'equipment_count')));
        $backlog = app(EstimatePrerequisiteBacklog::class)->current($session->fresh());
        $questions = $backlog['native_questions']['questions'];
        self::assertCount(1, $questions);
        $question = $questions[0];
        self::assertTrue($question['can_answer']);
        self::assertSame('project_parameter', $question['answer']['kind']);
        self::assertSame('equipment_count', $question['answer']['parameter']);
        self::assertSame('count', $question['input_contract']['unit']);
        self::assertSame(0, $question['input_contract']['decimal_places']);
        $answer = ['entities' => [[
            'key' => 'count-answer', 'type' => 'equipment', 'existing_entity_id' => $question['answer']['entity_id'],
            'native_question' => $question['answer']['native_question'],
            'parameters' => ['equipment_count' => ['value' => '3', 'unit' => 'count', 'basis' => 'assumption']],
        ]]];
        $requestId = (string) Str::uuid();
        $result = $writer->handle($actor, $scope[1], $scope[2], 1, $requestId, $answer);
        self::assertSame(2, $result['state_version']);
        self::assertSame(CanonicalPipelineJson::encode($result),
            CanonicalPipelineJson::encode($writer->handle($actor, $scope[1], $scope[2], 1, $requestId, $answer)));
        $after = $models->snapshotForPlanning(...[...$scope, 10001]);
        $facts = array_values(array_filter($after['snapshot']->facts,
            static fn (Fact $fact): bool => in_array($fact->id, $result['fact_ids'], true)));
        self::assertCount(1, $facts);
        $fact = $facts[0];
        self::assertSame('equipment_count', $fact->type);
        self::assertSame('3', $fact->value);
        self::assertSame('count', $fact->unit);
        self::assertSame('user_assumption', $fact->origin);
        self::assertSame([], $fact->nativeNumericProofs);
        self::assertCount(1, $models->decisionsForSelectedFacts(...[...$scope, [$fact->id]]));
        self::assertSame([], array_values(array_filter($after['snapshot']->facts,
            static fn (Fact $candidate): bool => $candidate->origin === 'document')));
        self::assertSame([], app(EstimatePrerequisiteBacklog::class)->current($session->fresh())['native_questions']['questions']);

        try {
            $writer->handle($actor, $scope[1], $scope[2], 2, (string) Str::uuid(), $answer);
            self::fail('A stale count question binding was accepted.');
        } catch (ValidationException) {
            self::assertSame(2, $session->fresh()->state_version);
        }
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $actor->id,
            'status' => 'draft', 'state_version' => 0,
            'input_payload' => ['evaluation_mode' => 'universal', 'selected_sections' => ['equipment'], 'building_type' => 'bridge'],
        ]);

        return [$actor, $session];
    }

    private function countAnswer(string $entityId, ?array $binding = null, string $value = '3'): array
    {
        $row = ['key' => 'count-answer', 'type' => 'equipment', 'existing_entity_id' => $entityId,
            'parameters' => ['equipment_count' => ['value' => $value, 'unit' => 'count', 'basis' => 'assumption']]];
        if ($binding !== null) {
            $row['native_question'] = $binding;
        }

        return ['entities' => [$row]];
    }

    private function nativeContext(EstimateGenerationSession $session, User $actor, string $checksum): DocumentUnitExecutionContext
    {
        $version = 'sha256:'.str_repeat($checksum, 64);
        $document = EstimateGenerationDocument::query()->create([
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'session_id' => $session->id, 'user_id' => $actor->id, 'filename' => 'source.xlsx',
            'mime_type' => 'application/octet-stream', 'source_version' => $version, 'checksum_sha256' => substr($version, 7),
        ]);
        $page = EstimateGenerationDocumentPage::query()->create([
            'organization_id' => $session->organization_id, 'project_id' => $session->project_id,
            'session_id' => $session->id, 'document_id' => $document->id, 'page_number' => 1, 'source_version' => $version,
        ]);

        return new DocumentUnitExecutionContext(1, (int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, (int) $document->id, DocumentUnitType::SpreadsheetSheet, 1, $version, [],
            'source.xlsx', 'application/octet-stream', 'source.xlsx', 'fixture-native', 1, 0, 'draft', (int) $page->id);
    }

    private function publishNative(DocumentUnitExecutionContext $context, ?string $count = null, array $entityKeys = ['equipment.p1']): void
    {
        $cells = [];
        $rows = [];
        foreach ($entityKeys as $entityKey) {
            $rows[] = [$entityKey, 'equipment_specification', 'Неизвестная установка', ''];
            if ($count !== null) {
                $rows[] = [$entityKey, 'equipment_count', $count, 'шт'];
            }
        }
        foreach ($rows as $index => $values) {
            foreach (array_combine(['A', 'B', 'C', 'D'], $values) as $column => $value) {
                $cells[] = ['address' => $column.($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Оборудование',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'],
                ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']], 'cells' => $cells]);
        $writer = app(AtomicDocumentUnitPublicationWriter::class);
        $writer->transaction($context->organizationId, $context->sessionId, fn () => $writer->write($publication,
            $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, 1, $context->sourceVersion));
    }

    private function norm(): EstimateNorm
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $version = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb_2022', 'version_key' => 'synthetic-knowledge-count-'.Str::uuid(),
            'bucket' => 'test', 'prefix' => 'test', 'status' => 'parsed', 'files_count' => 1,
            'rows_read' => 1, 'rows_imported' => 1, 'errors_count' => 0, ...$timestamps,
        ]);
        $collection = DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $version, 'code' => 'gesn', 'name' => 'Synthetic count fixture',
            'norm_type' => 'gesn', 'source_file' => 'synthetic.xml', ...$timestamps,
        ]);
        $section = DB::table('estimate_norm_sections')->insertGetId([
            'collection_id' => $collection, 'code' => '37', 'name' => 'Synthetic fixture',
            'section_type' => 'Сборник', 'depth' => 0, 'path' => '37', ...$timestamps,
        ]);
        $id = DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collection, 'section_id' => $section, 'code' => '37-01-001-01',
            'name' => 'Монтаж неизвестной установки', 'unit' => 'шт', 'section_code' => '37',
            'section_name' => 'Synthetic fixture', 'work_composition' => json_encode(['Монтаж'], JSON_UNESCAPED_UNICODE), ...$timestamps,
        ]);

        return EstimateNorm::query()->with('collection')->findOrFail($id);
    }

    private function regionalContext(EstimateNorm $norm): array
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $key = strtolower((string) Str::ulid());
        DB::table('estimate_norm_resources')->insert(['estimate_norm_id' => $norm->id, 'resource_code' => $key,
            'resource_name' => 'Synthetic labor fixture', 'unit' => 'h', 'quantity' => '1.000000',
            'resource_type' => 'labor', ...$timestamps]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'T-'.$key, 'name' => 'Synthetic region',
            'fgiscs_subject_id' => random_int(100000, 999999), ...$timestamps]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region,
            'name' => 'Synthetic zone', 'fgiscs_price_zone_id' => random_int(1000000, 1999999), ...$timestamps]);
        $period = DB::table('estimate_price_periods')->where('year', 2088)->where('quarter', 4)->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999),
                'name' => $key, 'year' => 2088, 'quarter' => 4, ...$timestamps]);
        $prices = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs',
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key,
            'status' => 'draft', ...$timestamps]);
        DB::table('estimate_regional_price_versions')->where('id', $prices)->update(['status' => 'active']);

        return ['region_id' => (int) $region, 'price_zone_id' => (int) $zone, 'period_id' => (int) $period,
            'estimate_regional_price_version_id' => (int) $prices, 'business_date' => '2088-10-01',
            'year' => 2088, 'quarter' => 4, 'version_key' => $key,
            'normative_dataset_version' => DB::table('estimate_dataset_versions')->where('id', $norm->collection->dataset_version_id)->value('version_key')];
    }
}
