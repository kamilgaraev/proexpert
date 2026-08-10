<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;

final class SheetRoleClassifier
{
    public function classify(VisionAnalysisData $analysis, ?string $nativeText = null): SheetRoleClassification
    {
        $declared = $analysis->projectSheetAnalysis?->sheetRole;
        $declaredRole = is_string($declared) ? SheetRole::tryFrom($declared) : null;
        if ($declaredRole === SheetRole::Unknown) {
            $declaredRole = null;
        }
        $inferredRole = $this->fromSheetType($analysis->sheetType);
        $hasExplicitSourceRole = $declaredRole !== null;
        $role = $declaredRole ?? $this->inferredRole($inferredRole, $nativeText);
        $facts = $analysis->projectSheetAnalysis?->facts ?? [];
        $reason = null;

        if ($hasExplicitSourceRole && $inferredRole !== SheetRole::Unknown && $declaredRole !== $inferredRole) {
            $reason = 'sheet_role_conflict';
        } elseif ($role->requiresStructuredFacts() && $facts === []) {
            $reason = 'sheet_role_insufficient_evidence';
        }

        return new SheetRoleClassification(
            $role,
            $declared,
            $hasExplicitSourceRole ? 1.0 : ($role === SheetRole::Unknown ? 0.0 : 0.85),
            $hasExplicitSourceRole ? 'provider_sheet_role' : ($role === SheetRole::Explication ? 'native_text_explication_marker' : 'sheet_type_inference'),
            $reason,
        );
    }

    private function fromSheetType(string $sheetType): SheetRole
    {
        return match ($sheetType) {
            'floor_plan', 'site_plan' => SheetRole::Plan,
            'section' => SheetRole::Section,
            'elevation' => SheetRole::Facade,
            'schedule' => SheetRole::Specification,
            default => SheetRole::Unknown,
        };
    }

    private function inferredRole(SheetRole $sheetTypeRole, ?string $nativeText): SheetRole
    {
        if ($sheetTypeRole !== SheetRole::Unknown) {
            return $sheetTypeRole;
        }

        return is_string($nativeText) && preg_match('/(?:экспликац|explication)/iu', $nativeText) === 1
            ? SheetRole::Explication
            : SheetRole::Unknown;
    }
}
