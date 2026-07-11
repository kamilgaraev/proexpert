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
            ->select(['d.id', 'd.status', 'd.source_version', 'd.checksum_sha256', 'd.structured_payload', 'd.facts_summary', 'd.quality_score', 'd.quality_level', 'd.quality_flags'])
            ->orderBy('d.id')
            ->limit(self::MAX_DOCUMENT_PROJECTIONS + 1)
            ->get();
        if ($rows->count() > self::MAX_DOCUMENT_PROJECTIONS) {
            throw new PipelineStageException(FailureCategory::UserActionRequired, 'pipeline_source_too_large');
        }
        $derivedVersions = $this->derivedVersions($session, $snapshot, $rows->all());
        $documents = $rows->map(static function (object $row) use ($derivedVersions): array {
            $sourceVersion = (string) $row->source_version;

            return [
                'id' => (int) $row->id,
                'source_version' => preg_match('/^sha256:[0-9a-f]{64}$/', $sourceVersion) === 1
                    ? $sourceVersion
                    : 'sha256:'.strtolower((string) $row->checksum_sha256),
                'status' => (string) $row->status,
                'derived_version' => $derivedVersions[(int) $row->id],
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

    /** @param list<object> $documents @return array<int, string> */
    private function derivedVersions(EstimateGenerationSession $session, FailureExecutionSnapshot $snapshot, array $documents): array
    {
        $connection = $session->getConnection();
        $definitions = $this->relationDefinitions();
        $countQueries = array_map(fn (array $definition) => $connection->table($definition[0])
            ->where('organization_id', $snapshot->organizationId)->where('project_id', $snapshot->projectId)
            ->where('session_id', $snapshot->sessionId)->selectRaw('COUNT(*) AS source_count'), $definitions);
        $union = array_shift($countQueries);
        foreach ($countQueries as $query) {
            $union->unionAll($query);
        }
        $counts = $connection->query()->fromSub($union, 'source_counts')
            ->selectRaw('COALESCE(SUM(source_count), 0) AS total_count, COALESCE(MAX(source_count), 0) AS maximum_count')->first();
        $hasher = new BoundedSourceVersionHasher;
        $hasher->assertCounts((int) $counts->total_count, (int) $counts->maximum_count);

        foreach ($documents as $document) {
            $hasher->start((int) $document->id, [
                'structured_payload' => $document->structured_payload, 'facts_summary' => $document->facts_summary,
                'quality_score' => $document->quality_score, 'quality_level' => $document->quality_level,
                'quality_flags' => $document->quality_flags,
            ]);
        }
        foreach ($definitions as [$table, $columns]) {
            foreach ($connection->table($table)->where('organization_id', $snapshot->organizationId)
                ->where('project_id', $snapshot->projectId)->where('session_id', $snapshot->sessionId)
                ->select($columns)->orderBy('document_id')->orderBy('id')->cursor() as $row) {
                $documentId = (int) $row->document_id;
                $hasher->update($documentId, get_object_vars($row));
            }
        }

        return $hasher->finish();
    }

    /** @return list<array{string, list<string>}> */
    private function relationDefinitions(): array
    {
        return [
            ['estimate_generation_document_facts', ['id', 'document_id', 'page_id', 'fact_type', 'scope_key', 'label', 'value_text', 'value_number', 'unit', 'confidence', 'source_ref', 'normalized_payload']],
            ['estimate_generation_drawing_elements', ['id', 'document_id', 'page_id', 'type', 'label', 'value_text', 'value_number', 'unit', 'bbox', 'geometry', 'confidence', 'source_ref', 'normalized_payload']],
            ['estimate_generation_quantity_takeoffs', ['id', 'document_id', 'page_id', 'source_element_ids', 'scope_key', 'work_intent', 'name', 'unit', 'quantity', 'formula', 'confidence', 'source_refs', 'normalized_payload']],
            ['estimate_generation_scope_inferences', ['id', 'document_id', 'page_id', 'inference_type', 'title', 'description', 'source_refs', 'normative_basis', 'work_intent', 'confidence', 'review_required', 'accepted_at']],
        ];
    }

    private function tooLarge(): never
    {
        throw new PipelineStageException(FailureCategory::UserActionRequired, 'pipeline_source_too_large');
    }
}
