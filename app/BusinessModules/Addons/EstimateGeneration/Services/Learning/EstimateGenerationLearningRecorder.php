<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EstimateGenerationLearningRecorder
{
    public function __construct(
        private readonly ImportedEstimateExampleExtractor $extractor,
        private readonly WorkIntentClassifier $workIntentClassifier,
        private readonly EquipmentCountKnowledgeValidationService $equipmentKnowledge,
        private readonly ComposerSourceClaimValidationService $sourceClaims,
        private readonly ProjectModelRepository $models,
    ) {}

    public function recordImportedEstimate(object $estimate, ?object $importSession = null): int
    {
        $created = 0;

        foreach ($this->extractor->extractFromImportedEstimate($estimate, $importSession) as $example) {
            $created += $this->record($example);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $originalWorkItem
     * @param  array<string, mixed>  $selectedWorkItem
     * @param  array<string, mixed>  $context
     */
    public function recordUserSelection(
        EstimateGenerationSession $session,
        array $originalWorkItem,
        array $selectedWorkItem,
        int $normId,
        array $context
    ): int {
        $norm = EstimateNorm::query()->with('collection')->find($normId);

        if (! $norm instanceof EstimateNorm) {
            return 0;
        }

        $workItemKey = $this->workItemKey($originalWorkItem);
        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($originalWorkItem);
        $workUnit = $this->nullableString($selectedWorkItem['unit'] ?? $originalWorkItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], $context);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_selection',
            'source_entity_type' => $packageItem instanceof EstimateGenerationPackageItem
                ? 'estimate_generation_package_item'
                : 'estimate_generation_work_item',
            'source_entity_id' => $packageItem?->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($selectedWorkItem['quantity'] ?? $originalWorkItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $this->normalizeNormCode((string) $norm->code),
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => (string) data_get($selectedWorkItem, 'normative_match.status', 'selected_by_user'),
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'offered_candidates' => $originalWorkItem['normative_candidates'] ?? [],
                'previous_normative_match' => $originalWorkItem['normative_match'] ?? null,
                'selected_norm_id' => $normId,
                'selected_normative_code' => $this->normalizeNormCode((string) $norm->code),
                'selection_source' => $this->nullableString($context['selection_source'] ?? null),
                'quantity_snapshot' => $this->quantitySnapshot($selectedWorkItem, $originalWorkItem),
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'section_title' => $context['section_title'] ?? null,
                ...$this->knowledgeContext($session, $originalWorkItem, $normId),
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_session',
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_selected'],
            'accepted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $originalWorkItem
     * @param  array<string, mixed>  $selectedWorkItem
     * @param  array<string, mixed>  $context
     */
    public function recordSupersededSelection(
        EstimateGenerationSession $session,
        array $originalWorkItem,
        array $selectedWorkItem,
        int $selectedNormId,
        array $context
    ): int {
        $previousNormId = $this->nullableInt(
            data_get($originalWorkItem, 'normative_match.norm_id')
            ?? data_get($originalWorkItem, 'normative_match.id')
        );

        if ($previousNormId === null || $previousNormId === $selectedNormId) {
            return 0;
        }

        $norm = EstimateNorm::query()->with('collection')->find($previousNormId);

        if (! $norm instanceof EstimateNorm) {
            return 0;
        }

        $workItemKey = $this->workItemKey($originalWorkItem);
        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($originalWorkItem);
        $workUnit = $this->nullableString($selectedWorkItem['unit'] ?? $originalWorkItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], $context);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_rejection',
            'source_entity_type' => $packageItem instanceof EstimateGenerationPackageItem
                ? 'estimate_generation_package_item'
                : 'estimate_generation_work_item',
            'source_entity_id' => $packageItem?->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($selectedWorkItem['quantity'] ?? $originalWorkItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $this->normalizeNormCode((string) $norm->code),
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'superseded_by_user_selection',
            'confidence' => 1.0,
            'is_positive' => false,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'rejected_norm_id' => $previousNormId,
                'rejected_normative_code' => $this->normalizeNormCode((string) $norm->code),
                'selected_norm_id' => $selectedNormId,
                'selected_normative_code' => $this->nullableString((string) data_get($selectedWorkItem, 'normative_match.code')),
                'selection_source' => $this->nullableString($context['selection_source'] ?? null),
                'quantity_snapshot' => $this->quantitySnapshot($selectedWorkItem, $originalWorkItem),
                'offered_candidates' => $originalWorkItem['normative_candidates'] ?? [],
                'previous_normative_match' => $originalWorkItem['normative_match'] ?? null,
                'local_estimate_title' => $context['local_estimate_title'] ?? null,
                'section_title' => $context['section_title'] ?? null,
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_session',
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_replaced'],
            'accepted_at' => now(),
        ]);
    }

    public function recordFeedbackDecision(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        if ($feedback->feedback_type === 'normative_confirmation') {
            return $this->recordUserConfirmation($session, $feedback);
        }

        if ($feedback->feedback_type === 'quantity_confirmation') {
            return $this->recordQuantityConfirmation($session, $feedback);
        }

        return $this->recordUserRejection($session, $feedback);
    }

    public function recordUserRejection(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        if (! in_array($feedback->feedback_type, ['normative_rejection', 'normative_correction'], true)) {
            return 0;
        }

        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $norm = $this->normFromPayload($payload);
        $normCode = $this->normalizeNormCode((string) ($payload['normative_code'] ?? $norm?->code ?? ''));

        if ($normCode === '') {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'user_rejection',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($workItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm?->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => $norm?->id,
            'norm_code' => $normCode,
            'normative_name' => $norm?->name,
            'normative_unit' => $norm?->unit,
            'decision_status' => 'rejected_by_user',
            'confidence' => 1.0,
            'is_positive' => false,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'rejected_norm_id' => $payload['norm_id'] ?? null,
                'rejected_normative_code' => $normCode,
                'selection_source' => $payload['selection_source'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'comments' => $feedback->comments,
                'offered_candidates' => $workItem['normative_candidates'] ?? [],
                'normative_match' => $workItem['normative_match'] ?? null,
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_feedback',
                'feedback_id' => (int) $feedback->id,
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_rejected'],
            'accepted_at' => now(),
        ]);
    }

    private function recordUserConfirmation(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $norm = $this->normFromPayload($payload);
        $normCode = $this->normalizeNormCode((string) (
            $payload['normative_code']
            ?? data_get($workItem, 'normative_match.code')
            ?? $norm?->code
            ?? ''
        ));

        if (! $norm instanceof EstimateNorm && $normCode !== '') {
            $norm = EstimateNorm::query()
                ->with('collection')
                ->where('code', $normCode)
                ->latest('id')
                ->first();
        }

        if (! $norm instanceof EstimateNorm || $normCode === '') {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? null);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'manual_review_choice',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $this->nullableFloat($workItem['quantity'] ?? null),
            'work_intent' => $this->intentPayload($intent),
            'normative_dataset_version_id' => $norm->collection?->dataset_version_id !== null
                ? (int) $norm->collection->dataset_version_id
                : null,
            'estimate_norm_id' => (int) $norm->id,
            'norm_code' => $normCode,
            'normative_name' => (string) $norm->name,
            'normative_unit' => (string) $norm->unit,
            'decision_status' => 'confirmed_by_user',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'confirmed_norm_id' => $payload['norm_id'] ?? data_get($workItem, 'normative_match.norm_id'),
                'confirmed_normative_code' => $normCode,
                'comments' => $feedback->comments,
                'normative_match' => $workItem['normative_match'] ?? null,
                'quantity_snapshot' => $this->quantitySnapshot($workItem, $workItem),
                ...$this->knowledgeContext($session, $workItem, (int) $norm->id),
            ],
            'source_refs' => [[
                'type' => 'estimate_generation_feedback',
                'feedback_id' => (int) $feedback->id,
                'session_id' => (int) $session->id,
                'package_item_id' => $packageItem?->id,
            ]],
            'quality_flags' => ['user_confirmed_normative'],
            'accepted_at' => now(),
        ]);
    }

    private function recordQuantityConfirmation(EstimateGenerationSession $session, EstimateGenerationFeedback $feedback): int
    {
        $payload = is_array($feedback->payload) ? $feedback->payload : [];
        $workItemKey = $feedback->work_item_key;
        $workItem = $this->findDraftWorkItem($session, $workItemKey);
        $quantity = $this->nullableFloat($workItem['quantity'] ?? $payload['quantity'] ?? null);

        if ($quantity === null || $quantity <= 0) {
            return 0;
        }

        $packageItem = $this->findPackageItem($session, $workItemKey);
        $workName = $this->workName($workItem);
        $workUnit = $this->nullableString($workItem['unit'] ?? $payload['unit'] ?? null);
        $quantityKey = $this->quantityKey($workItem, $workItemKey);
        $intent = $this->workIntentClassifier->classify([
            'name' => $workName,
            'unit' => $workUnit,
        ], [
            'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
        ]);

        return $this->record([
            'organization_id' => (int) $session->organization_id,
            'project_id' => $session->project_id !== null ? (int) $session->project_id : null,
            'source_type' => 'manual_quantity_confirmation',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => (int) $feedback->id,
            'generation_session_id' => (int) $session->id,
            'generation_package_item_id' => $packageItem?->id,
            'work_name' => $workName,
            'work_unit' => $workUnit,
            'work_quantity' => $quantity,
            'work_intent' => $this->intentPayload($intent),
            'estimate_norm_id' => null,
            'norm_code' => $this->quantityLearningCode($quantityKey),
            'normative_name' => null,
            'normative_unit' => null,
            'decision_status' => 'quantity_confirmed_by_user',
            'confidence' => 1.0,
            'is_positive' => true,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'work_item_key' => $workItemKey,
                'quantity_key' => $quantityKey,
                'quantity_formula' => $this->nullableString($workItem['quantity_formula'] ?? null),
                'quantity_basis' => $this->nullableString($workItem['quantity_basis'] ?? $payload['quantity_basis'] ?? null),
                'quantity_snapshot' => $this->quantitySnapshot($workItem, $payload),
                'quantity_feedback' => data_get($workItem, 'metadata.quantity_feedback'),
                'calculation_basis' => $this->nullableString(data_get($workItem, 'metadata.calculation_basis')),
                'comments' => $feedback->comments,
                'section_title' => $this->sectionTitleForWorkItem($session, $workItemKey),
            ],
            'source_refs' => $this->quantitySourceRefs($session, $feedback, $packageItem, $workItem),
            'quality_flags' => ['user_confirmed_quantity', 'manual_quantity_review'],
            'accepted_at' => now(),
        ]);
    }

    public function recordComposerSourceClaims(int $organizationId, int $projectId, int $sessionId, string $operationId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('composer_source_claim_requires_publication_transaction');
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->whereKey($sessionId)->lockForUpdate()->first();
        if ($session === null || ! in_array($session->status, [EstimateGenerationStatus::InputReviewRequired,
            EstimateGenerationStatus::EstimateReviewRequired, EstimateGenerationStatus::ReadyToApply], true)
            || ($session->analysis_payload['credit_operation_receipt']['operation_id'] ?? null) !== $operationId
            || ($session->input_payload['generation_credit_operation_id'] ?? null) !== $operationId) {
            return 0;
        }
        $receipts = $this->sourceClaimCandidatesForOperation($session, $operationId);
        if ($receipts === []) {
            return 0;
        }
        $scope = [$organizationId, $projectId, $sessionId];
        $capture = $this->sourceClaimPlanningSnapshot($scope);
        if ($capture === null) {
            return 0;
        }
        $snapshot = $capture['snapshot'];
        if (count($snapshot->facts) > 10000) {
            return 0;
        }
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $anchors = GeometryInventory::currentDocumentVersions($snapshot);
        $checked = $validated = [];
        foreach ($receipts as $receipt) {
            $result = $this->sourceClaims->capture($scope, $snapshot, $decisions, $session->input_payload ?? [],
                $receipt['candidate'], $anchors, $checked, $receipt['contract_version'] ?? null,
                $session->input_payload['rebuild_section_key'] ?? null);
            if ($result !== null) {
                $validated[] = [...$receipt, 'validation' => $result];
            }
        }
        $verified = $this->sourceClaimPlanningSnapshot($scope);
        if ($verified === null || ! hash_equals($capture['token'], $verified['token'])) {
            return 0;
        }
        $created = 0;
        foreach ($validated as $receipt) {
            $candidate = $receipt['candidate'];
            $validation = $receipt['validation'];
            $context = ['schema_version' => 1, 'candidate' => $candidate, 'source_claim_validation' => $validation,
                'training_allowed' => false];
            $created += DB::affectingStatement(<<<'SQL'
                INSERT INTO estimate_generation_learning_examples (
                    organization_id, project_id, generation_session_id, source_type, source_entity_type, source_entity_id,
                    source_claim_key, work_name, norm_code, decision_status, is_positive, accepted_at,
                    context_payload, source_refs, quality_flags, created_at, updated_at
                ) VALUES (?, ?, ?, 'composer_source_claim', 'ai_role_run', ?, ?, ?, NULL, 'proposed', false, NULL,
                    ?::jsonb, ?::jsonb, ?::jsonb, ?, ?)
                ON CONFLICT (organization_id, project_id, generation_session_id, source_claim_key) DO NOTHING
                SQL, [...$scope, $receipt['run_id'], $validation['source_claim_key'], $candidate['value']['data'],
                CanonicalPipelineJson::encode($context),
                CanonicalPipelineJson::encode(['role_run_id' => $receipt['run_id'], 'credit_operation_id' => $operationId,
                    'source_answer_fact_id' => $candidate['source_answer_fact_id'], 'document_fact_ids' => $candidate['document_fact_ids']]),
                json_encode(['source_correspondence_only', 'proposed_interpretation', 'do_not_index'], JSON_THROW_ON_ERROR), now(), now()]);
            $stored = DB::table('estimate_generation_learning_examples')->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('generation_session_id', $sessionId)->where('source_claim_key', $validation['source_claim_key'])->value('context_payload');
            $stored = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : null;
            if (! is_array($stored) || CanonicalPipelineJson::encode($stored['source_claim_validation']['identity'] ?? [])
                !== CanonicalPipelineJson::encode($validation['identity'])) {
                throw new \DomainException('composer_source_claim_identity_collision');
            }
        }

        return $created;
    }

    private function sourceClaimPlanningSnapshot(array $scope): ?array
    {
        try {
            return $this->models->snapshotForPlanning(...[...$scope, 10001]);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'planning_source_filter_budget_exceeded') {
                throw $exception;
            }

            return null;
        }
    }

    public function cellReviewProposalsForOperation(EstimateGenerationSession $session, string $operationId): array
    {
        if (! Str::isUuid($operationId) || $session->project_id === null) {
            return [];
        }
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)->where('session_id', $session->id)
            ->where('kind', 'generation')->where('status', 'settled')->whereNotNull('receipt')->first();
        $limit = $operation === null ? null : (json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR)['composer_part_limit'] ?? null);
        if (! is_int($limit) || $limit < 1 || $limit > ComposerSourceClaimValidationService::MAX_RECEIPTS) {
            return [];
        }
        $query = DB::table('estimate_generation_credit_attempts as credit')
            ->join('estimate_generation_vision_physical_attempts as physical', 'physical.attempt_id', '=', 'credit.attempt_id')
            ->join('estimate_generation_ai_role_runs as run', 'run.physical_attempt_id', '=', 'physical.attempt_id')
            ->where('credit.operation_id', $operationId)->where('physical.credit_operation_id', $operationId)
            ->where('credit.role', 'estimate_composition')->where('physical.credit_role', 'estimate_composition')
            ->whereIn('credit.status', ['recorded', 'unavailable'])->whereNotNull('credit.usage_transferred_at')
            ->where('run.status', 'completed')->where('run.role', AiAnalysisRole::EstimateComposer->value)
            ->where('run.subject_type', 'estimate_session')->where('run.subject_id', (string) $session->id)
            ->whereNull('run.document_id')->whereNull('run.page_id')
            ->whereIn('run.prompt_contract_version', \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer::CELL_RECEIPT_CONTRACTS)
            ->whereColumn('physical.request_fingerprint', 'run.input_fingerprint')->whereColumn('physical.logical_request_fingerprint', 'run.input_fingerprint')
            ->whereRaw("run.result_payload->'schema_version' = '3'::jsonb")
            ->whereRaw("octet_length(run.result_payload::text) <= 262144");
        foreach (['credit', 'physical', 'run'] as $table) {
            $query->where($table.'.organization_id', $session->organization_id)->where($table.'.project_id', $session->project_id)->where($table.'.session_id', $session->id);
        }
        $runs = $query->orderBy('run.id')->limit($limit + 1)->get(['run.*']);
        if ($runs->count() > $limit) {
            return [];
        }
        $result = $seen = [];
        foreach ($runs as $run) {
            $identity = new AiRoleRunInput((int) $run->organization_id, (int) $run->project_id, (int) $run->session_id,
                null, null, $run->subject_type, $run->subject_id, $run->subject_version, AiAnalysisRole::EstimateComposer, $run->model,
                $run->prompt_contract_version, $run->input_fingerprint);
            if (! hash_equals($identity->identityFingerprint(), $run->identity_fingerprint)) {
                return [];
            }
            $payload = json_decode($run->result_payload, true, 64, JSON_THROW_ON_ERROR);
            $allowed = ['schema_version', 'role', 'work_intents', 'quarantined_intents', 'result_state', 'cell_mapping_proposals', 'cell_mapping_context',
                ...(isset($payload['claim_candidates']) ? ['claim_candidates'] : [])];
            $contexts = $payload['cell_mapping_context'] ?? null;
            $proposals = $payload['cell_mapping_proposals'] ?? null;
            if (count($payload) !== count($allowed) || array_diff(array_keys($payload), $allowed) !== []
                || ($payload['role'] ?? null) !== 'estimate_composer' || ! is_array($payload['work_intents'] ?? null)
                || ! is_array($payload['quarantined_intents'] ?? null) || ! in_array($payload['result_state'] ?? null, ['ready', 'partial'], true)
                || ! is_array($contexts) || ! array_is_list($contexts) || count($contexts) > 8
                || ! is_array($proposals) || ! array_is_list($proposals) || count($proposals) > 8) {
                continue;
            }
            foreach ($proposals as $proposal) {
                $binding = \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SpreadsheetCellReviewSource::proposal($proposal, $contexts);
                if ($binding === null) {
                    continue;
                }
                $hash = hash('sha256', CanonicalPipelineJson::encode([$run->input_fingerprint, $proposal, $binding]));
                $row = $proposal['row_id'];
                if (isset($seen[$row]) && $seen[$row] !== $hash) {
                    unset($result[$row]);
                    $seen[$row] = 'conflict';
                    continue;
                }
                if (! isset($seen[$row])) {
                    $result[$row] = ['run_id' => (int) $run->id, 'operation_id' => $operationId, 'input_fingerprint' => $run->input_fingerprint,
                        'proposal_hash' => $hash, 'proposal' => $proposal, 'binding' => $binding];
                    $seen[$row] = $hash;
                }
            }
        }

        return count($seen) > 8 ? [] : array_values($result);
    }

    public function recordCellReview(EstimateGenerationSession $session, array $proposal,
        \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact $review): int
    {
        if (DB::transactionLevel() < 1 || ($review->value['kind'] ?? null) !== 'document_cell_review'
            || ($review->value['decision'] ?? null) !== 'accept') {
            throw new \LogicException('document_cell_review_transaction_required');
        }
        $receipts = $this->cellReviewProposalsForOperation($session, $proposal['operation_id']);
        if (count(array_filter($receipts, static fn (array $row): bool => $row['run_id'] === $proposal['run_id']
            && $row['proposal_hash'] === $proposal['proposal_hash'])) !== 1) {
            throw new \LogicException('document_cell_review_proposal_stale');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $capture = $this->sourceClaimPlanningSnapshot($scope);
        if ($capture === null) {
            throw new \LogicException('document_cell_review_context_unavailable');
        }
        $snapshot = $capture['snapshot'];
        $entities = array_column($snapshot->entities, null, 'id');
        $sources = array_values(array_filter($snapshot->facts, static fn ($fact): bool => $fact->type === 'equipment_specification'
            && ($entities[$fact->entityId]->attributes['properties']['document_cell_review_anchor'] ?? null) === $review->entityId));
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $validations = array_values(array_filter(array_map(
            fn ($source): ?array => $this->sourceClaims->captureCellReview($scope, $snapshot, $decisions, $source), $sources),
            static fn (?array $validation): bool => $validation !== null));
        $validation = count($validations) === 1 ? $validations[0] : null;
        if ($validation === null || $validation['candidate']['source_review_decision_id'] !== $review->value['decision_id']) {
            throw new \LogicException('document_cell_review_source_unresolved');
        }
        $candidate = $validation['candidate'];
        $context = ['schema_version' => 1, 'origin' => 'document_cell_review:v1', 'candidate' => $candidate,
            'proposal_hash' => $proposal['proposal_hash'], 'source_claim_validation' => $validation, 'training_allowed' => false];

        $created = DB::affectingStatement(<<<'SQL'
            INSERT INTO estimate_generation_learning_examples (
                organization_id, project_id, generation_session_id, source_type, source_entity_type, source_entity_id,
                source_claim_key, work_name, norm_code, decision_status, is_positive, accepted_at,
                context_payload, source_refs, quality_flags, created_at, updated_at
            ) VALUES (?, ?, ?, 'composer_source_claim', 'ai_role_run', ?, ?, ?, NULL, 'proposed', false, NULL,
                ?::jsonb, ?::jsonb, ?::jsonb, ?, ?)
            ON CONFLICT (organization_id, project_id, generation_session_id, source_claim_key) DO NOTHING
            SQL, [...$scope, $proposal['run_id'], $validation['source_claim_key'], $candidate['value']['data'],
            CanonicalPipelineJson::encode($context), CanonicalPipelineJson::encode(['role_run_id' => $proposal['run_id'],
                'credit_operation_id' => $proposal['operation_id'], 'source_review_decision_id' => $candidate['source_review_decision_id'],
                'document_fact_ids' => $candidate['document_fact_ids']]),
            json_encode(['source_correspondence_only', 'human_reviewed_interpretation', 'do_not_index'], JSON_THROW_ON_ERROR), now(), now()]);
        $stored = DB::table('estimate_generation_learning_examples')->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('generation_session_id', $scope[2])->where('source_claim_key', $validation['source_claim_key'])->value('context_payload');
        $stored = is_string($stored) ? json_decode($stored, true, 64, JSON_THROW_ON_ERROR) : null;
        if (! is_array($stored) || CanonicalPipelineJson::encode($stored['source_claim_validation']['identity'] ?? [])
            !== CanonicalPipelineJson::encode($validation['identity'])) {
            throw new \DomainException('composer_source_claim_identity_collision');
        }

        return $created;
    }

    public function sourceClaimCandidatesForOperation(EstimateGenerationSession $session, string $operationId): array
    {
        if ($session->project_id === null || ! Str::isUuid($operationId)) {
            return [];
        }
        $operation = DB::table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('session_id', $session->id)->where('kind', 'generation')->where('status', 'settled')->whereNotNull('receipt')->first();
        if ($operation === null) {
            return [];
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $limit = $plan['composer_part_limit'] ?? null;
        if (! is_int($limit) || $limit < 1 || $limit > ComposerSourceClaimValidationService::MAX_RECEIPTS) {
            return [];
        }
        $query = DB::table('estimate_generation_credit_attempts as credit')
            ->join('estimate_generation_vision_physical_attempts as physical', 'physical.attempt_id', '=', 'credit.attempt_id')
            ->join('estimate_generation_ai_role_runs as run', 'run.physical_attempt_id', '=', 'physical.attempt_id')
            ->where('credit.operation_id', $operationId)->where('physical.credit_operation_id', $operationId)
            ->where('credit.role', 'estimate_composition')->where('physical.credit_role', 'estimate_composition')
            ->whereIn('credit.status', ['recorded', 'unavailable'])->whereNotNull('credit.usage_transferred_at')
            ->where('run.status', 'completed')->where('run.role', AiAnalysisRole::EstimateComposer->value)
            ->where('run.subject_type', 'estimate_session')->where('run.subject_id', (string) $session->id)
            ->whereNull('run.document_id')->whereNull('run.page_id')
            ->whereIn('run.prompt_contract_version', ['estimate-composer:answers:v2', 'estimate-composer:legacy-answers:v2',
                'estimate-composer:answers:v3', 'estimate-composer:legacy-answers:v3',
                'estimate-composer:answers:v4', 'estimate-composer:legacy-answers:v4',
                'estimate-composer:answers:v5', 'estimate-composer:legacy-answers:v5',
                'estimate-composer:answer-cells:v1', 'estimate-composer:legacy-answer-cells:v1',
                'estimate-composer:answer-cells:v2', 'estimate-composer:legacy-answer-cells:v2',
                'estimate-composer:answer-cells:v3', 'estimate-composer:legacy-answer-cells:v3',
                'estimate-composer:answer-cells:v4', 'estimate-composer:legacy-answer-cells:v4'])
            ->whereColumn('physical.request_fingerprint', 'run.input_fingerprint')
            ->whereColumn('physical.logical_request_fingerprint', 'run.input_fingerprint')
            ->whereRaw("((run.prompt_contract_version IN ('estimate-composer:answers:v2','estimate-composer:legacy-answers:v2','estimate-composer:answers:v3','estimate-composer:legacy-answers:v3','estimate-composer:answers:v4','estimate-composer:legacy-answers:v4','estimate-composer:answers:v5','estimate-composer:legacy-answers:v5') AND run.result_payload->'schema_version' = '2'::jsonb
                AND run.result_payload - ARRAY['schema_version','role','work_intents','quarantined_intents','result_state','claim_candidates'] = '{}'::jsonb)
                OR (run.prompt_contract_version IN ('estimate-composer:answer-cells:v1','estimate-composer:legacy-answer-cells:v1','estimate-composer:answer-cells:v2','estimate-composer:legacy-answer-cells:v2','estimate-composer:answer-cells:v3','estimate-composer:legacy-answer-cells:v3','estimate-composer:answer-cells:v4','estimate-composer:legacy-answer-cells:v4') AND run.result_payload->'schema_version' = '3'::jsonb
                AND run.result_payload - ARRAY['schema_version','role','work_intents','quarantined_intents','result_state','claim_candidates','cell_mapping_proposals','cell_mapping_context'] = '{}'::jsonb
                AND jsonb_typeof(run.result_payload->'cell_mapping_proposals') = 'array' AND jsonb_typeof(run.result_payload->'cell_mapping_context') = 'array'))")
            ->whereRaw("run.result_payload->>'role' = ?", [AiAnalysisRole::EstimateComposer->value])
            ->whereRaw("CASE WHEN jsonb_typeof(run.result_payload->'claim_candidates') = 'array' THEN jsonb_array_length(run.result_payload->'claim_candidates') BETWEEN 0 AND ? ELSE false END", [ComposerSourceClaimValidationService::MAX_CLAIMS])
            ->whereRaw("octet_length((run.result_payload->'claim_candidates')::text) <= ?", [ComposerSourceClaimValidationService::MAX_CANDIDATE_JSON_BYTES])
            ->whereRaw("jsonb_typeof(run.result_payload->'work_intents') = 'array'")
            ->whereRaw("jsonb_typeof(run.result_payload->'quarantined_intents') = 'array'")
            ->whereRaw("run.result_payload->>'result_state' IN (?, ?)", ['ready', 'partial']);
        foreach (['credit', 'physical', 'run'] as $table) {
            $query->where($table.'.organization_id', $session->organization_id)
                ->where($table.'.project_id', $session->project_id)->where($table.'.session_id', $session->id);
        }
        $rows = $query->orderBy('run.id')->limit($limit + 1)->get(['run.id', 'run.organization_id', 'run.project_id', 'run.session_id',
            'run.document_id', 'run.page_id', 'run.subject_type', 'run.subject_id', 'run.subject_version', 'run.role', 'run.model',
            'run.prompt_contract_version', 'run.input_fingerprint', 'run.identity_fingerprint',
            DB::raw("run.result_payload->'claim_candidates' as claim_candidates")]);
        if ($rows->count() > $limit) {
            return [];
        }
        $receipts = [];
        foreach ($rows as $row) {
            $identity = new AiRoleRunInput((int) $row->organization_id, (int) $row->project_id, (int) $row->session_id,
                null, null, $row->subject_type, $row->subject_id, $row->subject_version, AiAnalysisRole::EstimateComposer,
                $row->model, $row->prompt_contract_version, $row->input_fingerprint);
            if (! hash_equals($row->identity_fingerprint, $identity->identityFingerprint())) {
                return [];
            }
            $receipts[] = ['run_id' => (int) $row->id,
                'candidates' => json_decode($row->claim_candidates, true, 64, JSON_THROW_ON_ERROR)];
        }

        $contracts = $rows->pluck('prompt_contract_version', 'id')->all();

        return array_map(static fn (array $receipt): array => [...$receipt,
            ...(in_array($contracts[$receipt['run_id']], \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput::WORK_SOURCE_CLAIM_CONTRACTS, true)
                ? ['contract_version' => $contracts[$receipt['run_id']]] : [])], ComposerSourceClaimValidationService::foldReceipts($receipts));
    }

    private function knowledgeContext(EstimateGenerationSession $session, array $workItem, int $normId): array
    {
        $knowledge = $this->equipmentKnowledge->capture($session, $workItem, $normId);

        return $knowledge === null ? [] : ['knowledge_validation' => $knowledge];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): int
    {
        $query = EstimateGenerationLearningExample::query();
        if (($attributes['source_entity_id'] ?? null) === null) {
            $workKey = data_get($attributes, 'context_payload.work_item_key');
            if (($attributes['source_type'] ?? null) === 'user_selection'
                && (! is_string($workKey) || trim($workKey) === ''
                    || ! is_int($attributes['organization_id'] ?? null) || ! is_int($attributes['generation_session_id'] ?? null)
                    || ! EstimateGenerationSession::query()->where('organization_id', $attributes['organization_id'])
                        ->where('project_id', $attributes['project_id'] ?? null)->whereKey($attributes['generation_session_id'])->exists())) {
                return 0;
            }
            $query->where('organization_id', $attributes['organization_id'])
                ->where('project_id', $attributes['project_id'] ?? null)
                ->where('generation_session_id', $attributes['generation_session_id'] ?? null);
            if (($attributes['source_entity_type'] ?? null) === 'estimate_generation_work_item') {
                $query->where('context_payload->work_item_key', $workKey);
            }
        }
        $example = $query->firstOrNew([
            'source_type' => (string) $attributes['source_type'],
            'source_entity_type' => $attributes['source_entity_type'] ?? null,
            'source_entity_id' => $attributes['source_entity_id'] ?? null,
            'norm_code' => (string) $attributes['norm_code'],
        ]);

        if ($example->exists && ! $this->canReplace($example, $attributes)) {
            return 0;
        }

        $wasNew = ! $example->exists;
        $example->fill($attributes);
        $example->save();

        return $wasNew ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function canReplace(EstimateGenerationLearningExample $example, array $attributes): bool
    {
        $currentQuality = (float) ($example->source_quality_score ?? 0);
        $nextQuality = (float) ($attributes['source_quality_score'] ?? 0);

        if ($example->is_positive && $currentQuality > $nextQuality) {
            return false;
        }

        return true;
    }

    private function findPackageItem(EstimateGenerationSession $session, ?string $workItemKey): ?EstimateGenerationPackageItem
    {
        if ($workItemKey === null || $workItemKey === '') {
            return null;
        }

        return EstimateGenerationPackageItem::query()
            ->where('key', $workItemKey)
            ->whereHas('package', static function ($query) use ($session): void {
                $query->where('session_id', $session->id);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function findDraftWorkItem(EstimateGenerationSession $session, ?string $workItemKey): array
    {
        if ($workItemKey === null || $workItemKey === '') {
            return [];
        }

        foreach (($session->draft_payload['local_estimates'] ?? []) as $localEstimate) {
            foreach (($localEstimate['sections'] ?? []) as $section) {
                foreach (($section['work_items'] ?? []) as $workItem) {
                    if ((string) ($workItem['key'] ?? '') === $workItemKey) {
                        return is_array($workItem) ? $workItem : [];
                    }
                }
            }
        }

        return [];
    }

    private function sectionTitleForWorkItem(EstimateGenerationSession $session, ?string $workItemKey): ?string
    {
        foreach (($session->draft_payload['local_estimates'] ?? []) as $localEstimate) {
            foreach (($localEstimate['sections'] ?? []) as $section) {
                foreach (($section['work_items'] ?? []) as $workItem) {
                    if ((string) ($workItem['key'] ?? '') === $workItemKey) {
                        return $this->nullableString($section['title'] ?? null);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normFromPayload(array $payload): ?EstimateNorm
    {
        $normId = $payload['norm_id'] ?? null;

        if ($normId !== null && $normId !== '') {
            $norm = EstimateNorm::query()->with('collection')->find((int) $normId);

            if ($norm instanceof EstimateNorm) {
                return $norm;
            }
        }

        $normCode = $this->normalizeNormCode((string) ($payload['normative_code'] ?? ''));

        if ($normCode === '') {
            return null;
        }

        return EstimateNorm::query()
            ->with('collection')
            ->where('code', $normCode)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function workItemKey(array $workItem): ?string
    {
        return $this->nullableString($workItem['key'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function workName(array $workItem): string
    {
        return trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? 'Позиция сметы'));
    }

    private function normalizeNormCode(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/^[^\d]*/u', '', $code) ?? $code;
        $code = preg_replace('/\s+/u', '', $code) ?? $code;

        return trim($code);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $workItem
     */
    private function quantityKey(array $workItem, ?string $workItemKey): string
    {
        $quantityKey = $this->nullableString(data_get($workItem, 'metadata.quantity_key'))
            ?? $this->nullableString($workItem['quantity_formula'] ?? null)
            ?? $this->nullableString($workItemKey)
            ?? 'unknown';

        return $quantityKey;
    }

    private function quantityLearningCode(string $quantityKey): string
    {
        return EstimateGenerationQuantityLearningKey::fromQuantityKey($quantityKey);
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return array<int, array<string, mixed>>
     */
    private function quantitySourceRefs(
        EstimateGenerationSession $session,
        EstimateGenerationFeedback $feedback,
        ?EstimateGenerationPackageItem $packageItem,
        array $workItem
    ): array {
        $sourceRefs = [[
            'type' => 'estimate_generation_feedback',
            'feedback_id' => (int) $feedback->id,
            'session_id' => (int) $session->id,
            'package_item_id' => $packageItem?->id,
        ]];

        foreach ($this->arrayValues($workItem['source_refs'] ?? []) as $sourceRef) {
            if (is_array($sourceRef)) {
                $sourceRefs[] = $sourceRef;
            }
        }

        return $sourceRefs;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValues(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $selectedWorkItem
     * @param  array<string, mixed>  $originalWorkItem
     * @return array<string, mixed>|null
     */
    private function quantitySnapshot(array $selectedWorkItem, array $originalWorkItem): ?array
    {
        $quantityFeedback = data_get($selectedWorkItem, 'metadata.quantity_feedback');

        if (! is_array($quantityFeedback)) {
            $quantityFeedback = data_get($originalWorkItem, 'metadata.quantity_feedback');
        }

        if (! is_array($quantityFeedback)) {
            $quantityFeedback = [];
        }

        $quantity = $this->nullableFloat(
            data_get($selectedWorkItem, 'quantity')
            ?? data_get($originalWorkItem, 'quantity')
            ?? ($quantityFeedback['quantity'] ?? null)
        );
        $unit = $this->nullableString(
            data_get($selectedWorkItem, 'unit')
            ?? data_get($originalWorkItem, 'unit')
            ?? ($quantityFeedback['unit'] ?? null)
        );
        $quantityBasis = $this->nullableString(
            data_get($selectedWorkItem, 'quantity_basis')
            ?? data_get($originalWorkItem, 'quantity_basis')
            ?? ($quantityFeedback['quantity_basis'] ?? null)
        );

        if ($quantity === null && $unit === null && $quantityBasis === null && $quantityFeedback === []) {
            return null;
        }

        return array_filter([
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_basis' => $quantityBasis,
            'confirmed_by_user' => ($quantityFeedback['status'] ?? null) === 'confirmed_by_user',
            'feedback' => $quantityFeedback !== [] ? $quantityFeedback : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(WorkIntentData $intent): array
    {
        return [
            'scope' => $intent->scope,
            'action' => $intent->action,
            'object' => $intent->object,
            'material' => $intent->material,
            'system' => $intent->system,
            'expected_dimensions' => $intent->expectedDimensions,
            'signals' => $intent->signals,
            'confidence' => $intent->confidence,
        ];
    }
}
