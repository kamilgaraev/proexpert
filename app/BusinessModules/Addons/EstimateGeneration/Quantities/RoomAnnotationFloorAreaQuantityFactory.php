<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\RoomAreaAnnotationParser;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class RoomAnnotationFloorAreaQuantityFactory
{
    public function __construct(
        private EvidenceRepository $evidence,
        private RoomAreaAnnotationParser $parser = new RoomAreaAnnotationParser,
    ) {}

    public function make(
        BuildingModelOperationContext $context,
        NormalizedBuildingModelData $model,
        ?int $expectedFloorCount = null,
    ): ?QuantityData {
        $total = BigDecimal::zero();
        $items = [];
        $evidenceIds = [];
        $usedEvidenceIds = [];
        $primaryFloorCount = 0;
        foreach ($model->assumptions as $assumption) {
            if ($assumption->severity === 'blocking'
                && ! in_array($assumption->code, ['scale_missing', 'scale_estimated'], true)) {
                return null;
            }
        }
        foreach ($model->floors as $floor) {
            $floorSource = $this->floorSource($context, $floor->rooms, $floor->evidenceIds);
            if ($floorSource === 'unknown') {
                return null;
            }
            if ($floorSource === 'reference') {
                continue;
            }
            if ($floor->rooms === []) {
                return null;
            }
            $primaryFloorCount++;
            foreach ($floor->rooms as $room) {
                $annotation = $this->parser->parse($room->name);
                if ($annotation === null) {
                    return null;
                }
                $areaEvidenceId = $this->areaEvidenceId($context, $room->evidenceIds, $annotation['area_m2']);
                if ($areaEvidenceId === null || isset($usedEvidenceIds[$areaEvidenceId])) {
                    return null;
                }
                $usedEvidenceIds[$areaEvidenceId] = true;
                if (! $annotation['included_in_floor_area']) {
                    continue;
                }
                $amount = BigDecimal::of((string) $annotation['area_m2'])->toScale(6, RoundingMode::HalfUp);
                $id = (string) $areaEvidenceId;
                $operand = [
                    'role' => 'area', 'value' => (string) $amount, 'unit' => 'm2', 'source' => 'estimated',
                    'evidence_ids' => [$id], 'assumptions' => ['vision_room_area_extraction'],
                    'context_id' => 'model:'.$model->modelVersion, 'provenance_version' => 'room-annotation:v1',
                ];
                $items[] = [
                    'identity' => hash('sha256', $room->key.'|'.$id.'|'.$amount),
                    'amount' => (string) $amount,
                    'evidence_ids' => [$id],
                    'provenance_versions' => ['room-annotation:v1'],
                    'named_operands' => ['area' => $operand],
                ];
                $evidenceIds[$id] = $id;
                $total = $total->plus($amount);
            }
        }
        if ($items === []
            || ($expectedFloorCount !== null && ($expectedFloorCount < 1 || $primaryFloorCount !== $expectedFloorCount))) {
            return null;
        }
        ksort($evidenceIds, SORT_NUMERIC);

        return new QuantityData(
            key: 'floor_area',
            unit: 'm2',
            amount: (string) $total->toScale(6, RoundingMode::HalfUp),
            formulaKey: 'document.rooms.internal_area_sum',
            formulaVersion: '1.0.0',
            formulaInputs: ['items' => $items],
            source: QuantitySource::Estimated,
            evidenceIds: array_values($evidenceIds),
            modelVersion: $model->modelVersion,
            assumptions: ['vision_room_area_extraction'],
        );
    }

    /** @param list<int> $ids */
    private function areaEvidenceId(BuildingModelOperationContext $context, array $ids, float $area): ?int
    {
        $nodes = $this->roomAreaNodes($context, $ids);
        if (count($nodes) !== 1) {
            return null;
        }
        $node = $nodes[0];
        if ($node->invalidatedAt !== null
            || $node->sourceType !== EvidenceSourceType::DocumentUnit
            || $node->producerName !== EvidenceProducer::DrawingAnalyzer->value
            || ! in_array($node->locator['unit_type'] ?? null, ['raster_image', 'sketch'], true)
            || $node->confidence < 0.85
            || ($node->value['unit'] ?? null) !== 'm2'
            || ! is_numeric($node->value['field_value'] ?? null)
            || abs((float) $node->value['field_value'] - $area) > 0.000001) {
            return null;
        }

        return $node->id;
    }

    /** @param list<int> $ids @return list<EvidenceNode> */
    private function roomAreaNodes(BuildingModelOperationContext $context, array $ids): array
    {
        $nodes = [];
        foreach ($ids as $id) {
            $node = $this->evidence->node($context->organizationId, $context->projectId, $context->sessionId, $id);
            if ($node !== null
                && $node->type === EvidenceType::Extracted
                && ($node->value['field_key'] ?? null) === 'room_area') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @param list<RoomData> $rooms @param list<int> $floorEvidenceIds */
    private function floorSource(
        BuildingModelOperationContext $context,
        array $rooms,
        array $floorEvidenceIds,
    ): string {
        $hasPrimary = false;
        $hasReference = false;
        $hasUnknown = false;
        $ids = $floorEvidenceIds;
        foreach ($rooms as $room) {
            $ids = [...$ids, ...$room->evidenceIds];
        }
        foreach (array_unique($ids) as $id) {
            $node = $this->evidence->node($context->organizationId, $context->projectId, $context->sessionId, $id);
            if ($node === null || $node->invalidatedAt !== null || $node->sourceType !== EvidenceSourceType::DocumentUnit) {
                $hasUnknown = true;

                continue;
            }
            $unitType = $node->locator['unit_type'] ?? null;
            if (in_array($unitType, ['raster_image', 'sketch'], true)
                && $node->producerName === EvidenceProducer::DrawingAnalyzer->value) {
                $hasPrimary = true;
            } elseif (in_array($unitType, ['pdf_page', 'cad_drawing'], true)) {
                $hasReference = true;
            } else {
                $hasUnknown = true;
            }
        }
        if ($hasUnknown || ($hasPrimary && $hasReference)) {
            return 'unknown';
        }

        return $hasPrimary ? 'primary' : ($hasReference ? 'reference' : 'unknown');
    }
}
