<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;

final readonly class AcceptedQuantityEvidenceMaterializer
{
    public function __construct(private EvidenceRepository $evidence) {}

    /** @param array<string, mixed> $workItem */
    public function materialize(PipelineContext $context, QuantityData $quantity, array $workItem): EvidenceNode
    {
        $formulaHash = hash('sha256', self::canonical([
            'key' => $quantity->formulaKey,
            'version' => $quantity->formulaVersion,
            'inputs' => $quantity->formulaInputs,
            'model_version' => $quantity->modelVersion,
        ]));
        $sourceIds = $quantity->evidenceIds;
        sort($sourceIds, SORT_STRING);
        $sourceEvidenceHash = hash('sha256', self::canonical($sourceIds));
        $logicalKey = (string) ($workItem['logical_key'] ?? $workItem['key'] ?? '');
        $itemIdentity = hash('sha256', $logicalKey);
        $workCodeIdentity = hash('sha256', self::canonical([
            'key' => $logicalKey,
            'quantity_key' => $quantity->key,
            'formula_hash' => $formulaHash,
            'source_evidence_hash' => $sourceEvidenceHash,
        ]));
        $parsed = \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer::parseDetailed($quantity->unit);
        $unit = ['м' => 'm', 'м2' => 'm2', 'м3' => 'm3', 'шт' => 'pcs', 'кг' => 'kg', 'ч' => 'h'][$parsed->baseUnit] ?? null;
        $factor = $unit === null ? null : \App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer::safeQuantityFactorDecimal($quantity->unit, $unit);
        if ($unit === null || $factor === null) {
            throw new \InvalidArgumentException('accepted_quantity_unit_unknown');
        }

        return $this->evidence->transaction($context->organizationId, $context->sessionId, fn (): EvidenceNode => $this->evidence->insertOrGet(new EvidenceData(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            type: EvidenceType::WorkItem,
            sourceType: EvidenceSourceType::Pipeline,
            sourceRef: 'pipeline:quantity_takeoff',
            sourceVersion: (string) $context->baseInputVersion,
            locator: ['item_key' => 'item:'.$itemIdentity],
            value: [
                'work_code' => 'work_type:'.$workCodeIdentity,
                'quantity' => (string) \Brick\Math\BigDecimal::of($quantity->amount)->multipliedBy($factor),
                'unit' => $unit,
            ],
            confidence: $quantity->reviewBlockers !== []
                ? 0.0
                : ($quantity->source === QuantitySource::Evidenced ? 1.0 : 0.65),
            producerName: EvidenceProducer::WorkPlanner->value,
            producerVersion: 'pipeline:v1',
        )));
    }

    private static function canonical(array $value): string
    {
        return DerivedQuantityIdentity::canonicalJson($value);
    }
}
