<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalEvidenceJson;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;

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
                'quantity' => $quantity->amount,
                'unit' => $quantity->unit,
            ],
            confidence: $quantity->reviewBlockers === [] ? 1.0 : 0.0,
            producerName: EvidenceProducer::WorkPlanner->value,
            producerVersion: 'pipeline:v1',
        )));
    }

    private static function canonical(array $value): string
    {
        return json_encode(CanonicalEvidenceJson::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
