<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use DomainException;

final readonly class EloquentPipelineOutputRepository implements PipelineOutputRepository
{
    private const MAX_AGGREGATE_BYTES = 8_388_608;

    public function __construct(private PipelineArtifactStore $artifacts) {}

    public function priorOutputs(PipelineContext $context): PipelinePriorOutputs
    {
        $rows = EstimateGenerationPipelineCheckpoint::query()
            ->where('session_id', $context->sessionId)
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('generation_attempt_id', $context->generationAttemptId)
            ->where('status', CheckpointStatus::Completed->value)
            ->orderBy('stage')
            ->orderByDesc('id')
            ->get(['stage', 'output_version', 'output_payload', 'artifact_bytes']);
        $outputs = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $payload = is_array($row->output_payload) ? $row->output_payload : [];
            $bytes += strlen(CanonicalPipelineJson::encode($payload));
            if ($bytes > self::MAX_AGGREGATE_BYTES) {
                throw new DomainException('Persisted pipeline outputs exceed the session bound.');
            }
            $output = PipelineStageOutput::fromEnvelope($payload, (string) $row->output_version);
            if ((int) $row->artifact_bytes !== $output->artifact->bytes || isset($outputs[$output->stage->value])) {
                continue;
            }
            $outputs[$output->stage->value] = $output;
        }

        return new PipelinePriorOutputs(
            $outputs,
            loader: fn (PipelineStageOutput $output): array => $this->artifacts->read($context, $output->artifact),
        );
    }
}
