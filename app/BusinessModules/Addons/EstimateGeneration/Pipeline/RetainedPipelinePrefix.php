<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RetainedPipelinePrefix
{
    public function __construct(
        private Connection $database,
        private PipelineOutputRepository $outputs,
        private EvidenceAwarePipelineBaseInputVersionResolver $baseVersions,
        private EvaluationInputFingerprint $inputs,
        private StageResultFactory $results,
        private EstimateGenerationRegionalContextResolver $regionalContext,
    ) {}

    public function capture(EstimateGenerationSession $session, array $known, int $actorId): ?array
    {
        if ($session->status->value !== 'failed' || $session->resume_status?->value !== 'generating'
            || ($session->input_payload['generation_actor_type'] ?? null) !== 'user'
            || ($session->input_payload['generation_actor_id'] ?? null) !== $actorId) {
            return null;
        }
        $changes = $this->regionalContext->retryChanges($session->input_payload ?? []);
        if ($changes !== [] && CanonicalPipelineJson::encode($changes['regional_context'])
            !== CanonicalPipelineJson::encode($session->input_payload['regional_context'])) {
            return null;
        }

        return $this->source($session, $session->input_payload['generation_attempt_id'] ?? null,
            $session->input_payload['generation_credit_operation_id'] ?? null, $known['fingerprint'] ?? null);
    }

    public function assertCurrent(EstimateGenerationSession $session, array $prefix, array $known, int $actorId): void
    {
        $current = $this->capture($session, $known, $actorId);
        if ($current === null || CanonicalPipelineJson::encode($current) !== CanonicalPipelineJson::encode($prefix)) {
            throw new DomainException('estimator_retained_pipeline_prefix_changed');
        }
    }

    public function reuse(PipelineContext $context): ?PipelineStageResult
    {
        if ($context->creditOperationId === null || $context->stage === null || $context->stage === ProcessingStage::ValidateDraft) {
            return null;
        }
        $operation = $this->database->table('estimate_generation_credit_operations')
            ->where('operation_id', $context->creditOperationId)->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)->where('session_id', $context->sessionId)
            ->where('kind', 'generation')->whereIn('status', ['reserved', 'running'])->first();
        if ($operation === null) {
            return null;
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $prefix = $plan['retained_pipeline_prefix'] ?? null;
        if ($prefix === null) {
            return null;
        }
        if (! is_array($prefix) || ! hash_equals((string) $operation->plan_hash,
            hash('sha256', CanonicalPipelineJson::encode($plan)))) {
            throw new DomainException('estimator_retained_pipeline_prefix_invalid');
        }
        $session = EstimateGenerationSession::query()->whereKey($context->sessionId)
            ->where('organization_id', $context->organizationId)->where('project_id', $context->projectId)->first();
        if ($session === null || (int) $session->state_version !== $context->stateVersion
            || $session->status->value !== $context->sessionStatus
            || ($session->input_payload['generation_attempt_id'] ?? null) !== $context->generationAttemptId
            || ($session->input_payload['generation_credit_operation_id'] ?? null) !== $context->creditOperationId
            || ! hash_equals((string) $operation->input_hash, $this->inputs->fromSession($session))) {
            throw new DomainException('estimator_retained_pipeline_prefix_changed');
        }
        $current = $this->source($session, $prefix['source_attempt_id'] ?? null,
            $prefix['source_operation_id'] ?? null, $plan['known_context']['fingerprint'] ?? null, validatePayloads: false);
        if ($current === null || CanonicalPipelineJson::encode($current) !== CanonicalPipelineJson::encode($prefix)
            || $context->baseInputVersion !== $prefix['base_input_version']) {
            throw new DomainException('estimator_retained_pipeline_prefix_changed');
        }
        $definition = PipelineDefinitionGraph::standard()->get($context->stage);
        if ($context->generationAttemptId !== $context->creditOperationId
            || $context->generationAttemptId === $prefix['source_attempt_id']
            || ! hash_equals(PipelineInputVersion::for($definition, $prefix['base_input_version'], $context->dependencyVersions), $context->inputVersion)) {
            throw new DomainException('estimator_retained_pipeline_prefix_invalid');
        }
        foreach ($definition->dependencies as $dependency) {
            $target = $context->priorOutputs->get($dependency);
            if ($target === null || $target->artifact->contentVersion !== $prefix['stages'][$dependency->value]['content_version']
                || $target->version !== ($context->dependencyVersions[$dependency->value] ?? null)) {
                throw new DomainException('estimator_retained_pipeline_dependency_changed');
            }
        }
        $sourceContext = $this->context($session, $prefix['base_input_version'], $prefix['source_attempt_id'], $prefix['source_operation_id']);
        $source = $this->outputs->priorOutputs($sourceContext);
        $payload = $source->payload($context->stage);
        $stage = $prefix['stages'][$context->stage->value];

        return $this->results->make($context, $context->stage, $payload,
            metrics: ['retained_prefix_reused' => true, 'source_attempt_id' => $prefix['source_attempt_id'],
                'source_output_version' => $stage['output_version']], warnings: $stage['warnings']);
    }

    private function source(EstimateGenerationSession $session, mixed $attempt, mixed $operationId, mixed $knownFingerprint, bool $validatePayloads = true): ?array
    {
        if (! is_string($attempt) || ! Str::isUuid($attempt) || ! is_string($operationId)
            || ! Str::isUuid($operationId) || $attempt !== $operationId || ! is_string($knownFingerprint)) {
            return null;
        }
        $operation = $this->database->table('estimate_generation_credit_operations')
            ->where('operation_id', $operationId)->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)
            ->where('kind', 'generation')->where('status', 'failed')->whereNotNull('receipt')->first();
        if ($operation === null || ! hash_equals((string) $operation->input_hash, $this->inputs->fromSession($session))) {
            return null;
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        if (! hash_equals((string) $operation->plan_hash, hash('sha256', CanonicalPipelineJson::encode($plan)))
            || ($plan['known_context']['fingerprint'] ?? null) !== $knownFingerprint
            || ($plan['operation_parameters'] ?? []) !== []) {
            return null;
        }
        $base = $this->baseVersions->fromSession($session);
        $context = $this->context($session, $base, $attempt, $operationId);
        try {
            $prior = $this->outputs->priorOutputs($context);
        } catch (InvalidArgumentException) {
            return null;
        }
        $checkpoints = EstimateGenerationPipelineCheckpoint::query()->where('session_id', $session->id)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('generation_attempt_id', $attempt)->where('status', CheckpointStatus::Completed->value)
            ->orderByDesc('id')->get()->unique('stage')->keyBy(static fn (EstimateGenerationPipelineCheckpoint $row): string => $row->stage->value);
        $stages = [];
        foreach (PipelineDefinitionGraph::standard()->ordered() as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                if (! isset($stages[$dependency->value])) {
                    return null;
                }
                $dependencies[$dependency->value] = $stages[$dependency->value]['output_version'];
            }
            $output = $prior->get($definition->stage);
            $checkpoint = $checkpoints->get($definition->stage->value);
            if ($output === null || $checkpoint === null || $checkpoint->invalidated_at !== null || $checkpoint->base_input_version !== $base
                || $checkpoint->input_version !== $output->inputVersion || $checkpoint->output_version !== $output->version
                || (int) $checkpoint->artifact_bytes !== $output->artifact->bytes
                || ! hash_equals(PipelineInputVersion::for($definition, $base, $dependencies), $output->inputVersion)
                || CanonicalPipelineJson::encode($dependencies) !== CanonicalPipelineJson::encode($output->dependencyVersions)
                || CanonicalPipelineJson::encode($dependencies) !== CanonicalPipelineJson::encode($checkpoint->dependency_versions)) {
                return null;
            }
            if ($validatePayloads) {
                try {
                    $prior->payload($definition->stage);
                } catch (TypedFailureException $error) {
                    if ($error->safeCode !== 'pipeline_artifact_integrity_failed') {
                        throw $error;
                    }

                    return null;
                } catch (DomainException|InvalidArgumentException) {
                    return null;
                }
            }
            $stages[$definition->stage->value] = ['output_version' => $output->version,
                'content_version' => $output->artifact->contentVersion, 'warnings' => $checkpoint->warnings ?? []];
            if ($definition->stage === ProcessingStage::BuildDraft) {
                return ['version' => 1, 'source_attempt_id' => $attempt, 'source_operation_id' => $operationId,
                    'source_plan_hash' => $operation->plan_hash, 'base_input_version' => $base, 'stages' => $stages];
            }
        }

        return null;
    }

    private function context(EstimateGenerationSession $session, string $base, string $attempt, string $operation): PipelineContext
    {
        return new PipelineContext((int) $session->id, (int) $session->organization_id, (int) $session->project_id,
            (int) $session->state_version, $base, $session->status->value, generationAttemptId: $attempt,
            baseInputVersion: $base, creditOperationId: $operation);
    }
}
