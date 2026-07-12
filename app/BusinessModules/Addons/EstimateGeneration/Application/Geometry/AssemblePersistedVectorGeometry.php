<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use JsonException;

final readonly class AssemblePersistedVectorGeometry
{
    public function __construct(
        private DatabaseManager $database,
        private GeometryBuildingModelInputMapper $mapper,
        private BuildingModelAssembler $assembler,
    ) {}

    public function handle(GeometryConfirmationCommand $command, ?int $confirmationEvidenceId = null): NormalizedBuildingModelData
    {
        if ($command->sourceConfirmation === null) {
            throw new InvalidArgumentException('Geometry source confirmation is required.');
        }
        $confirmation = GeometryConfirmationData::fromArray($command->sourceConfirmation);
        $rows = $this->database->table('estimate_generation_evidence')
            ->where('organization_id', $command->organizationId)->where('project_id', $command->projectId)
            ->where('session_id', $command->sessionId)->where('source_version', $command->expectedInputVersion)
            ->whereIn('producer_name', ['pdf_geometry', 'contract'])->limit(2)->get(['id', 'value']);
        if ($rows->count() !== 1 || ! is_string($rows[0]->value)) {
            throw new InvalidArgumentException('Confirmed geometry source was not found.');
        }
        $row = $rows[0];
        try {
            $value = json_decode($row->value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Confirmed geometry source is invalid.');
        }
        if (! is_array($value) || array_keys($value) !== ['vector_geometry']) {
            throw new InvalidArgumentException('Confirmed geometry source contract is invalid.');
        }
        $vector = VectorGeometryData::fromArray($value['vector_geometry']);
        $refs = [];
        foreach ($vector->entities as $entity) {
            $refs['vector:'.$entity['handle']] = (int) $row->id;
        }
        foreach ($confirmation->scaleEvidence as $evidence) {
            $reference = ($evidence['role'] === 'measured_segment' ? 'vector:' : 'confirmation:')
                .($evidence['value_handle'] ?? $evidence['entity_handle']);
            $refs[$reference] = $confirmationEvidenceId ?? (int) $row->id;
        }
        foreach ($confirmation->elements as $element) {
            if ($element['type'] === 'opening') {
                $refs['confirmation:'.$element['dimension_handle']] = $confirmationEvidenceId ?? (int) $row->id;
            }
        }
        $result = $this->assembler->assembleVision($this->mapper->map(null, $vector, $refs, 'floor-1', $confirmation));
        if ($result->clarifications !== [] || ($result->model->metrics['complete'] ?? false) !== true) {
            throw new InvalidArgumentException('Confirmed geometry remains incomplete.');
        }

        return $result->model;
    }
}
