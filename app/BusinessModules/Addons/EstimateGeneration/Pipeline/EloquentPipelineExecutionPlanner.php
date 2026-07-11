<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;

final readonly class EloquentPipelineExecutionPlanner implements PipelineExecutionPlanner
{
    private const MAX_DOCUMENT_PROJECTIONS = 500;

    public function __construct(
        private PipelineOutputRepository $outputs,
        private PipelinePlanResolver $resolver,
    ) {}

    public function next(FailureExecutionSnapshot $snapshot): ?PipelineContext
    {
        $session = EstimateGenerationSession::query()
            ->whereKey($snapshot->sessionId)
            ->where('organization_id', $snapshot->organizationId)
            ->where('project_id', $snapshot->projectId)
            ->select(['id', 'organization_id', 'project_id', 'status', 'state_version', 'input_payload'])
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || (int) $session->state_version !== $snapshot->stateVersion
            || $session->status->value !== $snapshot->status
            || ! hash_equals($snapshot->attemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            throw new StaleEstimateGenerationState($snapshot->sessionId, $snapshot->stateVersion);
        }

        $rows = $session->getConnection()->table('estimate_generation_documents AS d')
            ->where('d.organization_id', $snapshot->organizationId)
            ->where('d.project_id', $snapshot->projectId)
            ->where('d.session_id', $snapshot->sessionId)
            ->select(['d.id', 'd.status', 'd.source_version', 'd.checksum_sha256'])
            ->selectRaw($this->derivedVersionSql())
            ->orderBy('d.id')
            ->limit(self::MAX_DOCUMENT_PROJECTIONS + 1)
            ->get();
        if ($rows->count() > self::MAX_DOCUMENT_PROJECTIONS) {
            throw new PipelineStageException(FailureCategory::UserActionRequired, 'pipeline_source_too_large');
        }
        $documents = $rows->map(static function (object $row): array {
            $sourceVersion = (string) $row->source_version;

            return [
                'id' => (int) $row->id,
                'source_version' => preg_match('/^sha256:[0-9a-f]{64}$/', $sourceVersion) === 1
                    ? $sourceVersion
                    : 'sha256:'.strtolower((string) $row->checksum_sha256),
                'status' => (string) $row->status,
                'derived_version' => 'sha256:'.hash('sha256', (string) $row->derived_version),
            ];
        })->all();
        $baseInputVersion = PipelineBaseInputVersion::fromProjection(
            is_array($session->input_payload) ? $session->input_payload : [],
            $documents,
        );
        $seed = new PipelineContext(
            sessionId: $snapshot->sessionId,
            organizationId: $snapshot->organizationId,
            projectId: $snapshot->projectId,
            stateVersion: $snapshot->stateVersion,
            inputVersion: $baseInputVersion,
            sessionStatus: $snapshot->status,
            generationAttemptId: $snapshot->attemptId,
            baseInputVersion: $baseInputVersion,
        );

        return $this->resolver->next($seed, $this->outputs->priorOutputs($seed));
    }

    private function derivedVersionSql(): string
    {
        return <<<'SQL'
            md5(
                COALESCE(d.structured_payload::text, '') || '|' ||
                COALESCE(d.facts_summary::text, '') || '|' ||
                COALESCE(d.quality_score::text, '') || '|' ||
                COALESCE(d.quality_level, '') || '|' ||
                COALESCE(d.quality_flags::text, '') || '|' ||
                COALESCE((SELECT md5(string_agg(f.id::text || ':' || md5(to_jsonb(f)::text), '|' ORDER BY f.id)) FROM estimate_generation_document_facts f WHERE f.document_id = d.id AND f.organization_id = d.organization_id AND f.project_id = d.project_id AND f.session_id = d.session_id), '') || '|' ||
                COALESCE((SELECT md5(string_agg(e.id::text || ':' || md5(to_jsonb(e)::text), '|' ORDER BY e.id)) FROM estimate_generation_drawing_elements e WHERE e.document_id = d.id AND e.organization_id = d.organization_id AND e.project_id = d.project_id AND e.session_id = d.session_id), '') || '|' ||
                COALESCE((SELECT md5(string_agg(q.id::text || ':' || md5(to_jsonb(q)::text), '|' ORDER BY q.id)) FROM estimate_generation_quantity_takeoffs q WHERE q.document_id = d.id AND q.organization_id = d.organization_id AND q.project_id = d.project_id AND q.session_id = d.session_id), '') || '|' ||
                COALESCE((SELECT md5(string_agg(s.id::text || ':' || md5(to_jsonb(s)::text), '|' ORDER BY s.id)) FROM estimate_generation_scope_inferences s WHERE s.document_id = d.id AND s.organization_id = d.organization_id AND s.project_id = d.project_id AND s.session_id = d.session_id), '')
            ) AS derived_version
            SQL;
    }
}
