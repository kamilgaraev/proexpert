<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

final class DecisionEvidenceLineage
{
    private const UNTRUSTED_MARKERS = [
        'historical_conflict_ambiguous',
        'historical_conflict_unproven',
        'historical_evidence_unproven',
    ];

    public static function isTrusted(array $lineage, array $availableEvidenceIds): bool
    {
        $evidenceIds = [];
        foreach ($lineage as $item) {
            if (is_array($item) && in_array($item['limitation_code'] ?? null, self::UNTRUSTED_MARKERS, true)) {
                return false;
            }
            $evidenceId = is_string($item) ? $item : (is_array($item) ? ($item['evidence_id'] ?? null) : null);
            if (is_string($evidenceId)) {
                $evidenceIds[] = $evidenceId;
            }
        }

        return $evidenceIds !== [] && array_diff($evidenceIds, $availableEvidenceIds) === [];
    }
}
