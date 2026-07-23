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
        private DocumentFloorIdentityResolver $floorIdentity = new DocumentFloorIdentityResolver,
    ) {}

    /** @param list<SessionBuildingModelUnitData> $units @param array<string, mixed>|null $areaConstraint */
    public function store(BuildingModelOperationContext $context, array $units, ?array $areaConstraint = null): ?NormalizedBuildingModelData
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
            function () use ($context, $units, $areaConstraint): array {
                $inputs = $this->inputs($context, $units);
                $this->areaEvidence($context, $areaConstraint);

                return $inputs;
            },
        );
        if ($inputs === []) {
            return null;
        }

        $model = $this->assembler->assembleVisionMany($inputs);
        $this->models->store($context, $model);

        return $model;
    }

    /** @param array<string, mixed>|null $constraint */
    private function areaEvidence(BuildingModelOperationContext $context, ?array $constraint): ?\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode
    {
        if ($constraint === null
            || array_keys($constraint) !== ['total_area_m2', 'floor_count', 'document_id', 'source_version', 'confidence']
            || ! is_numeric($constraint['total_area_m2']) || (float) $constraint['total_area_m2'] <= 0
            || filter_var($constraint['floor_count'], FILTER_VALIDATE_INT) === false || (int) $constraint['floor_count'] < 1
            || filter_var($constraint['document_id'], FILTER_VALIDATE_INT) === false || (int) $constraint['document_id'] < 1
            || ! is_string($constraint['source_version']) || preg_match('/^sha256:[a-f0-9]{64}$/D', $constraint['source_version']) !== 1
            || ! is_numeric($constraint['confidence']) || ! is_finite((float) $constraint['confidence'])
            || (float) $constraint['confidence'] < 0 || (float) $constraint['confidence'] > 1) {
            return null;
        }

        return $this->evidence->insertOrGet(new EvidenceData(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            type: EvidenceType::SourceFact,
            sourceType: EvidenceSourceType::Document,
            sourceRef: 'document:'.$constraint['document_id'],
            sourceVersion: $constraint['source_version'],
            locator: ['document_id' => (int) $constraint['document_id']],
            value: [
                'fact_key' => 'area',
                'fact_value' => (float) $constraint['total_area_m2'],
                'unit' => 'm2',
            ],
            confidence: (float) $constraint['confidence'],
            producerName: EvidenceProducer::Pipeline->value,
            producerVersion: 'pipeline:v2',
        ));
    }

    /** @param list<SessionBuildingModelUnitData> $units @return list<VisionBuildingModelInputData> */
    private function inputs(BuildingModelOperationContext $context, array $units): array
    {
        $inputs = [];
        $unanchoredVectorInputs = [];
        $hasPrimaryRecognizedFloorPlan = $this->hasPrimaryRecognizedFloorPlan($units);
        $acceptedFloorDocuments = $this->acceptedFloorDocuments($units);
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
            if (is_array($visionPayload) && $this->isNonFloorVisionSource($visionPayload)) {
                continue;
            }
            if (is_array($visionPayload) && $this->isRejectedFloorSource($unit, $visionPayload, $acceptedFloorDocuments)) {
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
            $hasDetectedRoom = $vision !== null && $this->hasDetectedRoom($visionPayload);
            $hasPrimaryDetectedRoom = $hasDetectedRoom
                && in_array($unit->type->value, ['raster_image', 'sketch'], true);
            $hasExplicitFloorKey = is_string($unit->payload['floor_key'] ?? null);
            $roomAreaEvidenceIds = $vision !== null && $hasPrimaryDetectedRoom
                ? $this->roomAreaEvidence($context, $unit, $vision)
                : [];
            $input = $this->mapper->map($vision, $vector, $refs, $this->floorKey($unit), null, $roomAreaEvidenceIds);
            if ($hasPrimaryDetectedRoom || $hasExplicitFloorKey) {
                $inputs[] = $input;

                continue;
            }
            $unanchoredVectorInputs[] = $input;
        }

        return $hasPrimaryRecognizedFloorPlan ? $inputs : [...$inputs, ...$unanchoredVectorInputs];
    }

    /**
     * @param  list<SessionBuildingModelUnitData>  $units
     * @return array<string, int|null>
     */
    private function acceptedFloorDocuments(array $units): array
    {
        $sources = [];
        foreach ($units as $unit) {
            $vision = $unit->payload['vision_analysis'] ?? null;
            if (! in_array($unit->type->value, ['raster_image', 'sketch'], true)
                || ! is_array($vision)
                || $this->isNonFloorVisionSource($vision)
                || ! $this->hasDetectedRoom($vision)) {
                continue;
            }
            $identity = $this->recognizedFloorIdentity($unit);
            if ($identity === null) {
                continue;
            }
            [$floorKey, $priority] = $identity;
            $sources[$floorKey][$unit->documentId] = max(
                $priority,
                (int) ($sources[$floorKey][$unit->documentId] ?? 0),
            );
        }

        $accepted = [];
        foreach ($sources as $floorKey => $documents) {
            $highestPriority = max($documents);
            $authoritativeDocuments = array_keys(array_filter(
                $documents,
                static fn (int $priority): bool => $priority === $highestPriority,
            ));
            $accepted[$floorKey] = count($authoritativeDocuments) === 1
                ? (int) $authoritativeDocuments[0]
                : null;
        }

        return $accepted;
    }

    /** @param array<string, mixed> $vision @param array<string, int|null> $acceptedFloorDocuments */
    private function isRejectedFloorSource(
        SessionBuildingModelUnitData $unit,
        array $vision,
        array $acceptedFloorDocuments,
    ): bool {
        if (! in_array($unit->type->value, ['raster_image', 'sketch'], true) || ! $this->hasDetectedRoom($vision)) {
            return false;
        }
        $identity = $this->recognizedFloorIdentity($unit);
        if ($identity === null || ! array_key_exists($identity[0], $acceptedFloorDocuments)) {
            return false;
        }

        return $acceptedFloorDocuments[$identity[0]] !== $unit->documentId;
    }

    /** @return array{string, 1|2}|null */
    private function recognizedFloorIdentity(SessionBuildingModelUnitData $unit): ?array
    {
        $explicit = $unit->payload['floor_key'] ?? null;
        if (is_string($explicit) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}$/D', $explicit) === 1) {
            return [$explicit, 2];
        }
        $documentFloor = $this->floorIdentity->resolve(
            is_string($unit->payload['document_name'] ?? null) ? $unit->payload['document_name'] : null,
            is_string($unit->payload['document_title'] ?? null) ? $unit->payload['document_title'] : null,
        );

        return $documentFloor === null ? null : [$documentFloor, 1];
    }

    /** @param list<SessionBuildingModelUnitData> $units */
    private function hasPrimaryRecognizedFloorPlan(array $units): bool
    {
        foreach ($units as $unit) {
            $payload = $unit->payload['vision_analysis'] ?? null;
            if (in_array($unit->type->value, ['raster_image', 'sketch'], true)
                && is_array($payload)
                && ! $this->isNonFloorVisionSource($payload)
                && $this->hasDetectedRoom($payload)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function roomAreaEvidence(
        BuildingModelOperationContext $context,
        SessionBuildingModelUnitData $unit,
        VisionAnalysisData $vision,
    ): array {
        $parser = new RoomAreaAnnotationParser;
        $ids = [];
        foreach ($vision->elements as $element) {
            if ($element->type !== 'room') {
                continue;
            }
            $annotation = $parser->parse($element->label);
            if ($annotation === null) {
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
                    'region_key' => 'region:'.hash('sha256', $unit->unitId.'|'.$element->key),
                    'element_key' => 'element:'.hash('sha256', $unit->unitId.'|'.$element->key),
                    'bbox' => $this->polygonBbox($element->polygon),
                ],
                value: [
                    'field_key' => 'room_area',
                    'field_value' => $annotation['area_m2'],
                    'unit' => 'm2',
                ],
                confidence: min($unit->confidence, $element->confidence),
                producerName: EvidenceProducer::DrawingAnalyzer->value,
                producerVersion: 'model:v2',
            ));
            $ids[$element->key] = $node->id;
        }

        return $ids;
    }

    /** @param list<array{0: float, 1: float}> $polygon @return array{float, float, float, float} */
    private function polygonBbox(array $polygon): array
    {
        $x = array_column($polygon, 0);
        $y = array_column($polygon, 1);

        return [(float) min($x), (float) min($y), (float) max($x), (float) max($y)];
    }

    /** @param array<string, mixed> $payload */
    private function isNonFloorVisionSource(array $payload): bool
    {
        $sheetType = $payload['sheet_type'] ?? null;

        if ($sheetType === 'unknown' && $this->hasDetectedRoom($payload)) {
            return false;
        }

        return is_string($sheetType) && in_array($sheetType, [
            'elevation',
            'section',
            'detail',
            'site_plan',
            'schedule',
            'photo',
            'unknown',
        ], true);
    }

    /** @param array<string, mixed> $payload */
    private function hasDetectedRoom(array $payload): bool
    {
        $elements = $payload['elements'] ?? null;
        if (! is_array($elements)) {
            return false;
        }

        foreach ($elements as $element) {
            if (is_array($element) && ($element['type'] ?? null) === 'room') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function vision(array $payload): VisionAnalysisData
    {
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $core = array_intersect_key($payload, array_flip([
            'schema_version', 'sheet_type', 'evidence', 'elements', 'scale_candidates', 'warnings',
            'visual_attributes',
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
        $geometry = $payload['geometry'] ?? null;
        if (! is_array($geometry)) {
            throw new InvalidArgumentException('pdf_page_geometry_contract_invalid');
        }
        $pageNumber = $geometry['page_number'] ?? null;
        $width = $geometry['width'] ?? null;
        $height = $geometry['height'] ?? null;
        $rotation = $geometry['rotation'] ?? null;
        if (! is_int($pageNumber) || $pageNumber < 1
            || (! is_int($width) && ! is_float($width)) || (float) $width <= 0
            || (! is_int($height) && ! is_float($height)) || (float) $height <= 0
            || ! is_int($rotation) || ! in_array($rotation, [0, 90, 180, 270], true)
            || ! is_array($geometry['vector_elements'] ?? null)) {
            throw new InvalidArgumentException('pdf_page_geometry_contract_invalid');
        }
        $width = (float) $width;
        $height = (float) $height;
        $entities = [];
        foreach ($geometry['vector_elements'] as $index => $element) {
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
            'bounds' => [0.0, 0.0, $width, $height],
            'layers' => [['name' => 'page', 'visible' => true]],
            'blocks' => [],
            'entities' => $entities,
            'texts' => [],
            'dimensions' => [],
            'pages' => [[
                'page_number' => $pageNumber,
                'width' => $width,
                'height' => $height,
                'rotation' => $rotation,
                'media_box' => [0.0, 0.0, $width, $height],
                'crop_box' => [0.0, 0.0, $width, $height],
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
        $documentFloor = $this->floorIdentity->resolve(
            is_string($unit->payload['document_name'] ?? null) ? $unit->payload['document_name'] : null,
            is_string($unit->payload['document_title'] ?? null) ? $unit->payload['document_title'] : null,
        );
        if ($documentFloor !== null) {
            return $documentFloor;
        }
        if (($unit->payload['single_floor'] ?? false) === true || ($unit->payload['floor_count'] ?? null) === 1) {
            return 'floor-1';
        }

        return 'floor-document-'.$unit->documentId.'-page-'.$unit->index;
    }
}
