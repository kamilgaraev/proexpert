<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;

final class PhysicalMeasurementPublicationPolicy
{
    public static function requiresNumericProof(ObservationClaim $claim): bool
    {
        $numeric = $claim->value['data'] ?? null;
        if ((! is_string($numeric) && ! is_int($numeric) && ! is_float($numeric)) || ! is_numeric($numeric)) {
            return false;
        }

        // Numeric identifiers label objects; every numerical measurement needs a source.
        return $claim->unit !== null || ! in_array($claim->factType, ['material_code', 'element_type_code', 'room_name', 'room_number', 'sheet_number', 'document_number'], true);
    }

    public function admit(array $claims, array $decisions, ?VerifiedNativeNumericSources $sources): array
    {
        $byId = array_column($claims, null, 'id');

        return array_map(static function (ArbitrationDecision $decision) use ($byId, $sources): ArbitrationDecision {
            $claim = $byId[$decision->claimId] ?? null;
            if ($claim === null || $decision->status !== 'accepted' || ! self::requiresNumericProof($claim) || $sources?->certifies($claim) === true) {
                return $decision;
            }

            return new ArbitrationDecision($decision->claimId, 'candidate', $decision->supportingClaimIds, $decision->evidenceRefs,
                'numeric_source_requires_user_confirmation', $decision->canonicalClaim,
                'Локатор и совпадение выводов моделей не подтверждают числовое значение. Нужен проверенный исходный размер или ввод пользователя.');
        }, $decisions);
    }
}
