<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use Illuminate\Database\DatabaseManager;
use stdClass;

final readonly class EloquentBuildingModelReadDataSource implements BuildingModelReadDataSource
{
    public function __construct(private DatabaseManager $database) {}

    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first(['content_version', 'model']);
        if (! $row instanceof stdClass) {
            return null;
        }
        $model = $this->json($row->model);

        return $model === null ? null : [
            'content_version' => (string) $row->content_version,
            'model' => $model,
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

    /** @return list<string> */
    private function evidenceColumns(): array
    {
        return [
            'id', 'type', 'source_type', 'source_ref', 'source_version', 'locator', 'value',
            'confidence', 'producer_name', 'producer_version', 'invalidated_at',
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
}
