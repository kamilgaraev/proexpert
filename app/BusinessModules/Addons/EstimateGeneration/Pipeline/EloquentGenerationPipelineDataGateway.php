<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class EloquentGenerationPipelineDataGateway implements GenerationPipelineDataGateway
{
    public const MAX_DOCUMENTS = 500;

    public const MAX_SOURCE_ROWS = 10_000;

    public const MAX_SOURCE_BYTES = 6_291_456;

    public function __construct(
        private DatabaseManager $database,
        private BuildingModelRepository $buildingModels,
    ) {}

    public function manifest(PipelineContext $context): array
    {
        $session = $this->session($context);
        $documents = [];
        foreach ($this->documentQuery($context)->select(['id', 'checksum_sha256', 'source_version'])->limit(self::MAX_DOCUMENTS + 1)->cursor() as $row) {
            if (count($documents) >= self::MAX_DOCUMENTS) {
                $this->tooLarge();
            }
            $documents[] = [
                'id' => (int) $row->id,
                'source_version' => $this->sourceVersion($row),
            ];
        }

        $input = $this->json($session->input_payload);

        return [
            'base_input_version' => (string) $context->baseInputVersion,
            'documents' => $documents,
            'documents_count' => count($documents),
            'rebuild_section_key' => is_string($input['rebuild_section_key'] ?? null)
                ? $input['rebuild_section_key'] : null,
        ];
    }

    public function source(PipelineContext $context): array
    {
        $session = $this->session($context);
        $documents = [];
        $bytes = 0;
        foreach ($this->documentQuery($context)->select([
            'id', 'checksum_sha256', 'source_version', 'status', 'structured_payload', 'facts_summary',
            'quality_score', 'quality_level', 'quality_flags',
        ])->limit(self::MAX_DOCUMENTS + 1)->cursor() as $row) {
            if (count($documents) >= self::MAX_DOCUMENTS) {
                $this->tooLarge();
            }
            $document = [
                'id' => (int) $row->id,
                'source_version' => $this->sourceVersion($row),
                'status' => (string) $row->status,
                'structured_payload' => $this->json($row->structured_payload),
                'facts_summary' => $this->json($row->facts_summary),
                'quality' => [
                    'score' => $row->quality_score === null ? null : (float) $row->quality_score,
                    'level' => $row->quality_level,
                    'flags' => $this->jsonList($row->quality_flags),
                ],
                'facts' => [],
                'drawing_elements' => [],
                'quantity_takeoffs' => [],
                'scope_inferences' => [],
            ];
            $bytes = $this->boundedBytes($bytes, $document);
            $documents[(int) $row->id] = $document;
        }

        $rows = 0;
        foreach ($this->relationDefinitions() as [$table, $target, $columns]) {
            $query = $this->scoped($this->database->connection()->table($table), $context)
                ->select($columns)->orderBy('id');
            foreach ($query->cursor() as $row) {
                if (++$rows > self::MAX_SOURCE_ROWS) {
                    $this->tooLarge();
                }
                $documentId = (int) $row->document_id;
                if (! isset($documents[$documentId])) {
                    continue;
                }
                $value = $this->row($row);
                $bytes = $this->boundedBytes($bytes, $value);
                $documents[$documentId][$target][] = $value;
            }
        }

        $model = $this->buildingModels->currentModel(new BuildingModelOperationContext(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $context->baseInputVersion,
        ));

        return [
            'input' => $this->json($session->input_payload),
            'documents' => array_values($documents),
            'user_id' => $session->user_id === null ? null : (int) $session->user_id,
            'normalized_building_model' => $model?->toArray(),
        ];
    }

    private function session(PipelineContext $context): stdClass
    {
        $session = $this->database->connection()->table('estimate_generation_sessions')
            ->where('id', $context->sessionId)
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->select(['id', 'user_id', 'status', 'state_version', 'input_payload'])
            ->first();
        $input = $session instanceof stdClass ? $this->json($session->input_payload) : [];
        if (! $session instanceof stdClass
            || (int) $session->state_version !== $context->stateVersion
            || (string) $session->status !== $context->sessionStatus
            || $context->generationAttemptId === null
            || ! hash_equals($context->generationAttemptId, (string) ($input['generation_attempt_id'] ?? ''))) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState(
                $context->sessionId,
                $context->stateVersion,
            );
        }

        return $session;
    }

    private function documentQuery(PipelineContext $context): Builder
    {
        return $this->scoped(
            $this->database->connection()->table('estimate_generation_documents'),
            $context,
        )->orderBy('id');
    }

    private function scoped(Builder $query, PipelineContext $context): Builder
    {
        return $query->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId);
    }

    /** @return list<array{string, string, list<string>}> */
    private function relationDefinitions(): array
    {
        return [
            ['estimate_generation_document_facts', 'facts', ['id', 'document_id', 'page_id', 'fact_type', 'scope_key', 'label', 'value_text', 'value_number', 'unit', 'confidence', 'source_ref', 'normalized_payload']],
            ['estimate_generation_drawing_elements', 'drawing_elements', ['id', 'document_id', 'page_id', 'type', 'label', 'value_text', 'value_number', 'unit', 'bbox', 'geometry', 'confidence', 'source_ref', 'normalized_payload']],
            ['estimate_generation_quantity_takeoffs', 'quantity_takeoffs', ['id', 'document_id', 'page_id', 'source_element_ids', 'scope_key', 'work_intent', 'name', 'unit', 'quantity', 'formula', 'confidence', 'source_refs', 'normalized_payload']],
            ['estimate_generation_scope_inferences', 'scope_inferences', ['id', 'document_id', 'page_id', 'inference_type', 'title', 'description', 'source_refs', 'normative_basis', 'work_intent', 'confidence', 'review_required', 'accepted_at']],
        ];
    }

    private function sourceVersion(stdClass $row): string
    {
        $source = (string) ($row->source_version ?? '');

        return preg_match('/^sha256:[0-9a-f]{64}$/', $source) === 1
            ? $source
            : 'sha256:'.strtolower((string) $row->checksum_sha256);
    }

    /** @return array<string, mixed> */
    private function row(stdClass $row): array
    {
        $value = get_object_vars($row);
        foreach ($value as $key => $item) {
            if (is_string($item) && ($item === '{}' || $item === '[]' || str_starts_with($item, '{') || str_starts_with($item, '['))) {
                $decoded = json_decode($item, true);
                if (is_array($decoded)) {
                    $value[$key] = $decoded;
                }
            }
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<mixed> */
    private function jsonList(mixed $value): array
    {
        return array_values($this->json($value));
    }

    private function boundedBytes(int $bytes, array $value): int
    {
        $bytes += strlen(CanonicalPipelineJson::encode($value));
        if ($bytes > self::MAX_SOURCE_BYTES) {
            $this->tooLarge();
        }

        return $bytes;
    }

    private function tooLarge(): never
    {
        throw new PipelineStageException(FailureCategory::UserActionRequired, 'pipeline_source_too_large');
    }
}
