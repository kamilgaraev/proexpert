<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EvidenceAwarePipelineBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class EstimateAuditDraftPreview
{
    public function __construct(
        private PipelineOutputRepository $outputs,
        private EvidenceAwarePipelineBaseInputVersionResolver $baseVersions,
        private EstimateAuditInputFactory $inputs,
        private ApplyComposerCorrectionCycle $cycle,
        private EstimateGenerationActionAuthorization $authorization,
    ) {}

    public function read(User $actor, int $projectId, int $sessionId): array
    {
        $session = EstimateGenerationSession::query()->whereKey($sessionId)
            ->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)->first();
        if ($session === null) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        $unavailable = ['preview_only' => true, 'cost_units' => 0, 'available' => false,
            'state_version' => (int) $session->state_version, 'validated' => false];
        $attempt = $session->input_payload['generation_attempt_id'] ?? null;
        $operation = $session->input_payload['generation_credit_operation_id'] ?? null;
        if (! is_string($attempt) || ! Str::isUuid($attempt)
            || ($operation !== null && (! is_string($operation) || ! Str::isUuid($operation)))) {
            return [...$unavailable, 'reason' => 'current_build_draft_unavailable'];
        }
        $base = $this->baseVersions->fromSession($session);
        $context = new PipelineContext((int) $session->id, (int) $session->organization_id,
            (int) $session->project_id, (int) $session->state_version, $base, $session->status->value,
            generationAttemptId: $attempt, baseInputVersion: $base, creditOperationId: $operation);
        $prior = $this->outputs->priorOutputs($context);
        $build = null;
        foreach (PipelineDefinitionGraph::standard()->ordered() as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                $output = $prior->get($dependency);
                if ($output === null) {
                    return [...$unavailable, 'reason' => 'current_build_draft_unavailable'];
                }
                $dependencies[$dependency->value] = $output->version;
            }
            $output = $prior->get($definition->stage);
            if ($output === null || ! hash_equals(PipelineInputVersion::for($definition, $base, $dependencies), $output->inputVersion)
                || CanonicalPipelineJson::encode($output->dependencyVersions) !== CanonicalPipelineJson::encode($dependencies)) {
                return [...$unavailable, 'reason' => 'current_build_draft_stale'];
            }
            if ($definition->stage === ProcessingStage::BuildDraft) {
                $build = $output;
                break;
            }
        }
        $checkpoint = EstimateGenerationPipelineCheckpoint::query()
            ->where('organization_id', $context->organizationId)->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId)->where('generation_attempt_id', $attempt)
            ->where('stage', ProcessingStage::BuildDraft->value)->where('status', CheckpointStatus::Completed->value)
            ->whereNull('invalidated_at')->orderByDesc('id')->first();
        if ($build === null || $checkpoint === null || $checkpoint->base_input_version !== $base
            || $checkpoint->input_version !== $build->inputVersion || $checkpoint->output_version !== $build->version
            || (int) $checkpoint->artifact_bytes !== $build->artifact->bytes
            || CanonicalPipelineJson::encode($checkpoint->dependency_versions) !== CanonicalPipelineJson::encode($build->dependencyVersions)) {
            return [...$unavailable, 'reason' => 'current_build_draft_stale'];
        }
        $draft = $prior->payload(ProcessingStage::BuildDraft)['draft'];
        $root = $this->inputs->capture($context->organizationId, $context->projectId, $context->sessionId, $draft,
            new AiSessionWireScope($context->stateVersion, $attempt, $operation));
        $metrics = ['facts' => count($root->facts), 'evidence' => count($root->evidence),
            'derived_quantities' => array_map(static function (array $quantity): array {
                $basis = is_array($quantity['quantity_basis'] ?? null) ? $quantity['quantity_basis'] : [];

                return ['id' => $quantity['id'] ?? null, 'bytes' => strlen(CanonicalPipelineJson::encode($quantity)),
                    'field_bytes' => array_map(self::bytes(...), $quantity),
                    'basis_field_bytes' => array_map(self::bytes(...), $basis),
                    'formula_input_field_bytes' => array_map(self::bytes(...), $basis['formula_inputs'] ?? []),
                    'operand_bytes' => array_map(self::bytes(...), $basis['formula_inputs']['operands'] ?? []),
                    'operand_count' => count($basis['formula_inputs']['operands'] ?? []),
                    'operand_fact_count' => count($quantity['operand_fact_ids'] ?? [])];
            }, $root->derivedQuantities)];
        try {
            $prepared = $this->cycle->prepareParts($root);
            $result = ['fits' => true, 'part_limit' => $prepared['part_limit'],
                'maximum_part_bytes' => $prepared['maximum_part_bytes'],
                'maximum_serialized_input_bytes' => $prepared['maximum_serialized_input_bytes'],
                'parts' => array_map(static function (array $part): array {
                    $encoded = CanonicalPipelineJson::encode(['audit_input' => $part['input']->canonicalPayload()]);

                    return ['id' => $part['id'], 'work_keys' => $part['work_keys'], 'payload_bytes' => strlen($encoded),
                        'serialized_input_upper' => EstimatorTextWirePolicy::serializedInputUpper([
                            ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                            ['role' => 'user', 'content' => $encoded],
                        ]), 'quantity_ids' => array_column($part['input']->derivedQuantities, 'id')];
                }, $prepared['parts'])];
        } catch (InvalidArgumentException $exception) {
            if (! in_array($exception->getMessage(), ['estimate_audit_context_requires_source_split',
                'estimate_audit_parts_exceed_approved_plan'], true)) {
                throw $exception;
            }
            $result = ['fits' => false, 'blocked_by' => $exception->getMessage()];
        }
        $fresh = EstimateGenerationSession::query()->whereKey($context->sessionId)
            ->where('organization_id', $context->organizationId)->where('project_id', $context->projectId)->first();
        if ($fresh === null || (int) $fresh->state_version !== $context->stateVersion || $fresh->status->value !== $context->sessionStatus
            || ($fresh->input_payload['generation_attempt_id'] ?? null) !== $attempt
            || ($fresh->input_payload['generation_credit_operation_id'] ?? null) !== $operation
            || ! hash_equals($base, $this->baseVersions->fromSession($fresh))) {
            return [...$unavailable, 'reason' => 'current_build_draft_stale'];
        }

        return [...$unavailable, 'available' => true, 'artifact_version' => $build->version,
            'audit_preview' => [...$result, ...$metrics]];
    }

    private static function bytes(mixed $value): int
    {
        return strlen(CanonicalPipelineJson::encode([$value])) - 2;
    }
}
