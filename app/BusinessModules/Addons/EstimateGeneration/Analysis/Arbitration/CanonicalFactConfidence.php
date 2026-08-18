<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

final class CanonicalFactConfidence
{
    public function forDecision(ArbitrationDecision $decision, array $claims): float
    {
        $byId = [];
        foreach ($claims as $key => $claim) {
            if ($claim instanceof ObservationClaim) {
                $byId[is_string($key) ? $key : $claim->id] = $claim;
            }
        }
        $lineage = [];
        foreach ($decision->supportingClaimIds as $claimId) {
            $claim = $byId[$claimId] ?? null;
            if (! $claim instanceof ObservationClaim) {
                continue;
            }
            $lineage[] = ['role' => $claim->observerRole, 'confidence' => $claim->confidence];
        }
        if ($lineage === []) {
            $primary = $byId[$decision->claimId] ?? null;

            return $primary instanceof ObservationClaim ? round($primary->confidence, 4) : 0.0;
        }

        return $this->forLineage($lineage, $decision->status === 'accepted');
    }

    public function forLineage(array $lineage, bool $accepted): float
    {
        $roles = [];
        foreach ($lineage as $item) {
            $role = is_array($item) && is_string($item['role'] ?? null) ? $item['role'] : null;
            $confidence = is_array($item) && is_numeric($item['confidence'] ?? null)
                ? (float) $item['confidence']
                : null;
            if ($role === null || $confidence === null || ! is_finite($confidence)
                || $confidence < 0 || $confidence > 1) {
                continue;
            }
            $roles[$role] = max($roles[$role] ?? 0.0, $confidence);
        }
        if ($roles === []) {
            return 0.0;
        }
        $mean = array_sum($roles) / count($roles);
        $bonus = $accepted ? 0.02 * (count($roles) - 1) : 0.0;

        return round(min(0.99, max(0.0, $mean + $bonus)), 4);
    }
}
