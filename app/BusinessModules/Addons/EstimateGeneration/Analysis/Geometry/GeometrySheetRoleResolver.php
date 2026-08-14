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
    public function resolve(array $observerResults): ?string
    {
        $votes = [];
        foreach ($observerResults as $result) {
            $sheetType = $result->payload['observation']['sheet_type'] ?? null;
            $role = is_string($sheetType) ? (self::ROLES[$sheetType] ?? null) : null;
            if ($role !== null) {
                $votes[$role] = ($votes[$role] ?? 0) + 1;
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes, SORT_NUMERIC);
        $roles = array_keys($votes);

        return ($votes[$roles[0]] ?? 0) >= 2 ? $roles[0] : null;
    }
}
