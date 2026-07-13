<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\VisionBuildingModelInputData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use InvalidArgumentException;

final readonly class SessionBuildingModelBridge
{
    public function __construct(
        private EvidenceRepository $evidence,
        private GeometryBuildingModelInputMapper $mapper,
        private BuildingModelAssembler $assembler,
        private BuildingModelRepository $models,
    ) {}

    /** @param list<SessionBuildingModelUnitData> $units */
    public function store(BuildingModelOperationContext $context, array $units): ?NormalizedBuildingModelData
    {
        foreach ($units as $unit) {
            if (! $unit instanceof SessionBuildingModelUnitData) {
                throw new InvalidArgumentException('Building model unit list is invalid.');
            }
        }
        usort($units, static fn (SessionBuildingModelUnitData $left, SessionBuildingModelUnitData $right): int => [
            $left->documentId, $left->index, $left->unitId,
        ] <=> [
            $right->documentId, $right->index, $right->unitId,
        ]);

        $inputs = $this->evidence->transaction(
            $context->organizationId,
            $context->sessionId,
            fn (): array => $this->inputs($context, $units),
        );
        if ($inputs === []) {
            return null;
        }

        $model = $this->assembler->assembleVisionMany($inputs);
        $this->models->store($context, $model);

        return $model;
    }

    /** @param list<SessionBuildingModelUnitData> $units @return list<VisionBuildingModelInputData> */
    private function inputs(BuildingModelOperationContext $context, array $units): array
    {
        $inputs = [];
        foreach ($units as $unit) {
            $visionPayload = $unit->payload['vision_analysis'] ?? null;
            $vectorPayload = $unit->payload['vector_geometry'] ?? null;
            if (! is_array($visionPayload) && ! is_array($vectorPayload)) {
                continue;
            }

            $node = $this->evidence->insertOrGet(new EvidenceData(
                organizationId: $context->organizationId,
                projectId: $context->projectId,
                sessionId: $context->sessionId,
                type: EvidenceType::Extracted,
                sourceType: EvidenceSourceType::DocumentUnit,
                sourceRef: 'document:'.$unit->documentId,
                sourceVersion: $unit->sourceVersion,
                locator: [
                    'document_id' => $unit->documentId,
                    'unit_type' => $unit->type->value,
                    'unit_index' => $unit->index,
                    'page' => $unit->index,
                ],
                value: ['field_key' => 'element_type_code', 'field_value' => 'element_type:floor'],
                confidence: $unit->confidence,
                producerName: is_array($vectorPayload)
                    ? EvidenceProducer::PdfGeometry->value
                    : EvidenceProducer::DrawingAnalyzer->value,
                producerVersion: is_array($vectorPayload) ? 'extractor:v1' : 'model:v1',
            ));

            $vision = is_array($visionPayload) ? $this->vision($visionPayload) : null;
            $vector = is_array($vectorPayload) ? VectorGeometryData::fromArray($vectorPayload) : null;
            $refs = [];
            foreach ($vision?->evidence ?? [] as $item) {
                $refs[$item->key] = $node->id;
            }
            if ($vector !== null) {
                foreach ([...$vector->entities, ...$vector->texts, ...$vector->dimensions] as $item) {
                    if (is_string($item['handle'] ?? null)) {
                        $refs['vector:'.$item['handle']] = $node->id;
                    }
                }
            }
            $inputs[] = $this->mapper->map($vision, $vector, $refs, 'floor-1');
        }

        return $inputs;
    }

    /** @param array<string, mixed> $payload */
    private function vision(array $payload): VisionAnalysisData
    {
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $core = array_intersect_key($payload, array_flip([
            'schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings',
        ]));

        return VisionAnalysisData::fromProviderArray(
            $core,
            (string) ($payload['provider'] ?? ''),
            (string) ($payload['requested_model'] ?? ''),
            (string) ($payload['reported_model'] ?? ''),
            (string) ($payload['model_version'] ?? ''),
            (string) ($usage['status'] ?? ''),
            is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            500,
        );
    }
}
