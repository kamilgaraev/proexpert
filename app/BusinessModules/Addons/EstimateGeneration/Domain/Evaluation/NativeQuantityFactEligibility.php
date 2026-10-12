<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;

final class NativeQuantityFactEligibility
{
    /** @param array<string, Evidence> $evidence */
    public function evidence(Fact $fact, array $evidence, string $workUnit): ?Evidence
    {
        $unit = DirectQuantityBinding::canonicalUnit($fact->unit);
        if ($fact->origin !== 'document' || $fact->status !== 'confirmed' || $unit === null
            || NormativeUnitNormalizer::safeQuantityFactorDecimal($unit, $workUnit) === null
            || (! is_string($fact->value) && ! is_int($fact->value)) || ! OrdinaryEstimateDecimal::fitsQuantity($fact->value)
            || ! BigDecimal::of((string) $fact->value)->isGreaterThan(0)) {
            return null;
        }
        foreach ($fact->evidenceIds as $id) {
            if (isset($evidence[$id]) && (new NativeNumericFactProof)->matches($fact, $evidence[$id])) {
                return $evidence[$id];
            }
        }

        return null;
    }
}
