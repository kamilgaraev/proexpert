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
            $pdfGeometryPayload = $unit->payload['pdf_geometry'] ?? null;
            if (! is_array($vectorPayload) && is_array($pdfGeometryPayload)) {
                $vectorPayload = $this->pdfVector($unit, $pdfGeometryPayload);
            }
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
            $inputs[] = $this->mapper->map($vision, $vector, $refs, $this->floorKey($unit));
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

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function pdfVector(SessionBuildingModelUnitData $unit, array $payload): array
    {
        $pageNumber = is_int($payload['page_number'] ?? null) && $payload['page_number'] > 0
            ? $payload['page_number']
            : $unit->index;
        $width = is_int($payload['width'] ?? null) || is_float($payload['width'] ?? null) ? (float) $payload['width'] : 1.0;
        $height = is_int($payload['height'] ?? null) || is_float($payload['height'] ?? null) ? (float) $payload['height'] : 1.0;
        $rotation = is_int($payload['rotation'] ?? null) && in_array($payload['rotation'], [0, 90, 180, 270], true)
            ? $payload['rotation']
            : 0;
        $entities = [];
        foreach (is_array($payload['vector_elements'] ?? null) ? $payload['vector_elements'] : [] as $index => $element) {
            $points = is_array($element) && is_array($element['geometry']['points'] ?? null)
                ? $element['geometry']['points']
                : null;
            if (($element['kind'] ?? null) !== 'line' || ! is_array($points) || count($points) !== 2) {
                continue;
            }
            $entities[] = [
                'handle' => 'pdf-p'.$pageNumber.'-s'.$index,
                'type' => 'line',
                'layer' => 'page',
                'points' => $points,
                'layout' => 'page:'.$pageNumber,
            ];
        }

        return [
            'schema_version' => 1,
            'runtime_version' => 'pdf-geometry:v1;pypdfium2:5.8.0',
            'source_fingerprint' => $unit->sourceVersion,
            'source_unit' => null,
            'unit_status' => 'unknown',
            'bounds' => [0.0, 0.0, max(1.0, $width), max(1.0, $height)],
            'layers' => [['name' => 'page', 'visible' => true]],
            'blocks' => [],
            'entities' => $entities,
            'texts' => [],
            'dimensions' => [],
            'pages' => [[
                'page_number' => $pageNumber,
                'width' => max(1.0, $width),
                'height' => max(1.0, $height),
                'rotation' => $rotation,
                'media_box' => [0.0, 0.0, max(1.0, $width), max(1.0, $height)],
                'crop_box' => [0.0, 0.0, max(1.0, $width), max(1.0, $height)],
                'transform' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
                'classification' => $entities === [] ? 'empty' : 'vector',
            ]],
            'scale_candidates' => [],
            'warnings' => [],
        ];
    }

    private function floorKey(SessionBuildingModelUnitData $unit): string
    {
        $explicit = $unit->payload['floor_key'] ?? null;
        if (is_string($explicit) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}$/D', $explicit) === 1) {
            return $explicit;
        }
        if (($unit->payload['single_floor'] ?? false) === true || ($unit->payload['floor_count'] ?? null) === 1) {
            return 'floor-1';
        }

        return 'floor-page-'.$unit->index;
    }
}
