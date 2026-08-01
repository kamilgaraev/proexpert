<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DocumentTotalAreaConstraintResolver;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionChainProjector;
use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class EloquentBuildingModelReadDataSource implements BuildingModelReadDataSource
{
    public function __construct(
        private DatabaseManager $database,
        private DocumentTotalAreaConstraintResolver $areaConstraints = new DocumentTotalAreaConstraintResolver,
        private ProjectModelCorrectionChainProjector $corrections = new ProjectModelCorrectionChainProjector,
    ) {}

    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first(['id', 'organization_id', 'project_id', 'session_id', 'content_version', 'model']);
        if (! $row instanceof stdClass) {
            return null;
        }
        $model = $this->json($row->model);

        return $model === null ? null : [
            'content_version' => (string) $row->content_version,
            'model' => $model,
            'effective_values' => $this->corrections->project($this->correctionRows($row)),
        ];
    }

    public function evidenceForIds(int $organizationId, int $projectId, int $sessionId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('id', $ids)
            ->whereNull('invalidated_at')
            ->get($this->evidenceColumns())
            ->mapWithKeys(fn (stdClass $row): array => [(int) $row->id => $this->evidenceRow($row)])
            ->all();
    }

    public function evidence(int $organizationId, int $projectId, int $sessionId, int $evidenceId): ?array
    {
        $row = $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('id', $evidenceId)
            ->whereNull('invalidated_at')
            ->first($this->evidenceColumns());

        return $row instanceof stdClass ? $this->evidenceRow($row) : null;
    }

    public function documentNames(int $organizationId, int $projectId, int $sessionId, array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds, static fn (int $id): bool => $id > 0)));
        if ($documentIds === []) {
            return [];
        }

        return $this->database->table('estimate_generation_documents')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->whereIn('id', $documentIds)
            ->pluck('filename', 'id')
            ->mapWithKeys(static fn (mixed $filename, mixed $id): array => [(int) $id => (string) $filename])
            ->all();
    }

    public function totalArea(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $documents = $this->database->table('estimate_generation_documents')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get(['id', 'status', 'quality_level', 'quality_score', 'source_version', 'facts_summary'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
                'quality_level' => $row->quality_level,
                'quality_score' => $row->quality_score,
                'source_version' => (string) $row->source_version,
                'facts_summary' => $this->json($row->facts_summary) ?? [],
            ])
            ->all();
        $constraint = $this->areaConstraints->resolve($documents);
        if ($constraint === null) {
            return null;
        }

        $rows = $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('type', 'source_fact')
            ->where('source_type', 'document')
            ->where('producer_name', 'pipeline')
            ->where('producer_version', 'pipeline:v2')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->limit(100)
            ->get($this->evidenceColumns());
        foreach ($rows as $row) {
            $evidence = $this->evidenceRow($row);
            if (! $this->areaConstraints->matchesEvidence($constraint, $evidence)) {
                continue;
            }

            return [
                'amount' => number_format($constraint['total_area_m2'], 6, '.', ''),
                'evidence_id' => (int) $row->id,
                'confidence' => max(0.0, min(1.0, (float) $row->confidence)),
                'floor_count' => $constraint['floor_count'],
                'source_version' => (string) $row->source_version,
                'fingerprint' => (string) $row->fingerprint,
                'invalidation_version' => (int) $row->invalidation_version,
                'active' => $row->invalidated_at === null,
            ];
        }

        return null;
    }

    /** @return list<string> */
    private function evidenceColumns(): array
    {
        return [
            'id', 'type', 'source_type', 'source_ref', 'source_version', 'locator', 'value',
            'confidence', 'producer_name', 'producer_version', 'fingerprint', 'invalidation_version', 'invalidated_at',
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceRow(stdClass $row): array
    {
        return [
            ...get_object_vars($row),
            'id' => (int) $row->id,
            'locator' => $this->json($row->locator) ?? [],
            'value' => $this->json($row->value) ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string, mixed>> */
    private function correctionRows(stdClass $model): array
    {
        return $this->database->table('estimate_generation_project_model_corrections as correction')
            ->join('estimate_generation_project_model_assertions as assertion', 'assertion.id', '=', 'correction.assertion_id')
            ->join('estimate_generation_project_model_entities as entity', 'entity.id', '=', 'assertion.entity_id')
            ->where('correction.building_model_id', (int) $model->id)
            ->where('correction.organization_id', $model->organization_id ?? null)
            ->where('correction.project_id', $model->project_id ?? null)
            ->where('correction.session_id', $model->session_id ?? null)
            ->where('correction.source_version', (string) $model->content_version)
            ->orderBy('correction.id')
            ->get([
                'correction.stable_key as correction_stable_key',
                'correction.payload as correction_payload',
                'assertion.stable_key as assertion_stable_key',
                'assertion.assertion_type',
                'assertion.payload as assertion_payload',
                'entity.stable_key as entity_stable_key',
            ])
            ->map(function (stdClass $row): array {
                $correction = $this->json($row->correction_payload);
                $assertion = $this->json($row->assertion_payload);
                if ($correction === null || $assertion === null) {
                    throw new \UnexpectedValueException('Project model correction history is invalid.');
                }

                return [
                    'correction_stable_key' => (string) $row->correction_stable_key,
                    'correction_payload' => $correction,
                    'assertion_stable_key' => (string) $row->assertion_stable_key,
                    'assertion_type' => (string) $row->assertion_type,
                    'assertion_payload' => $assertion,
                    'entity_stable_key' => (string) $row->entity_stable_key,
                ];
            })
            ->all();
    }
}
