<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;

final class GeometrySheetRoleResolver
{
    private const ROLES = [
        'floor_plan' => 'plan',
        'plan' => 'plan',
        'section' => 'section',
        'elevation' => 'facade',
        'facade' => 'facade',
        'roof_plan' => 'roof',
        'roof' => 'roof',
        'explication' => 'explication',
        'specification' => 'specification',
    ];

    /** @param array<string, AiRoleRunResult> $observerResults */
    public function resolve(array $observerResults, AiRoleRunResult $arbitration): ?string
    {
        if (($arbitration->payload['role'] ?? null) !== 'arbiter') {
            return null;
        }
        $accepted = [];
        foreach (is_array($arbitration->payload['decisions'] ?? null) ? $arbitration->payload['decisions'] : [] as $decision) {
            if (! is_array($decision) || ($decision['status'] ?? null) !== 'accepted') {
                continue;
            }
            $claimIds = array_values(array_unique(array_filter([
                $decision['claim_id'] ?? null,
                ...(is_array($decision['supporting_claim_ids'] ?? null) ? $decision['supporting_claim_ids'] : []),
            ], 'is_string')));
            foreach ($claimIds as $claimId) {
                $prefix = strstr($claimId, ':', true);
                $observerRole = is_string($prefix) ? 'observer_'.$prefix : '';
                $sheetType = $observerResults[$observerRole]->payload['observation']['sheet_type'] ?? null;
                $role = is_string($sheetType) ? (self::ROLES[$sheetType] ?? null) : null;
                if ($role !== null) {
                    $accepted[$role] = ($accepted[$role] ?? 0) + 1;
                }
            }
        }
        if ($accepted === []) {
            return null;
        }
        arsort($accepted, SORT_NUMERIC);
        $roles = array_keys($accepted);
        if (isset($roles[1]) && $accepted[$roles[0]] === $accepted[$roles[1]]) {
            return null;
        }

        return $roles[0];
    }
}
