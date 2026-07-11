<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class EloquentBuildingModelStore implements BuildingModelStore
{
    public function __construct(private Connection $database) {}

    public function transaction(BuildingModelOperationContext $context, callable $callback): mixed
    {
        return $this->database->transaction(function () use ($context, $callback): mixed {
            if ($this->database->getDriverName() === 'pgsql') {
                $this->database->select('SELECT pg_advisory_xact_lock(?, ?)', [$context->organizationId, $context->sessionId]);
            }
            $session = $this->database->table('estimate_generation_sessions')
                ->where('id', $context->sessionId)
                ->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)
                ->lockForUpdate()
                ->first(['id']);
            if ($session === null) {
                throw new RuntimeException('estimate_generation.building_model_session_not_found');
            }

            return $callback();
        }, 3);
    }

    public function insertOrGet(BuildingModelOperationContext $context, NormalizedBuildingModelData $model): StoredBuildingModel
    {
        $contentVersion = $model->contentVersion();
        $created = $this->database->table('estimate_generation_building_models')->insertOrIgnore([
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'session_id' => $context->sessionId,
            'input_version' => $context->inputVersion,
            'model_version' => $model->modelVersion,
            'content_version' => $contentVersion,
            'scale_status' => $model->scaleStatus,
            'scale_meters_per_unit' => $model->scaleMetersPerUnit,
            'model' => BuildingModelSchema::canonicalJson($model->toArray()),
            'assumptions' => BuildingModelSchema::canonicalJson(array_map(static fn ($assumption): array => $assumption->toArray(), $model->assumptions)),
            'metrics' => BuildingModelSchema::canonicalJson($model->metrics),
            'created_at' => now(),
        ]) === 1;
        $row = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId)
            ->where('input_version', $context->inputVersion)
            ->first(['id', 'model_version', 'content_version']);
        if ($row === null) {
            throw new RuntimeException('estimate_generation.building_model_record_failed');
        }
        if (! hash_equals((string) $row->content_version, $contentVersion)) {
            throw new BuildingModelContentCollision;
        }

        return new StoredBuildingModel((int) $row->id, $context, (string) $row->model_version, (string) $row->content_version, $created);
    }

    public function attachEvidence(StoredBuildingModel $stored, array $evidenceIds): void
    {
        foreach ($evidenceIds as $evidenceId) {
            $this->database->table('estimate_generation_building_model_evidence')->insertOrIgnore([
                'building_model_id' => $stored->id,
                'evidence_id' => $evidenceId,
                'organization_id' => $stored->context->organizationId,
                'project_id' => $stored->context->projectId,
                'session_id' => $stored->context->sessionId,
                'created_at' => now(),
            ]);
        }
    }
}
