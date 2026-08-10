<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRoutingResult;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;

final class TargetedSheetRecheckPlanner
{
    public function plan(
        int $documentId,
        int $pageId,
        SheetAnalysisRoutingResult $routing,
        VisionAnalysisData $analysis,
        ?TargetedSheetEvidence $peer,
    ): ?TargetedSheetRecheckPlan {
        $role = $routing->classification->role->value;
        $reason = $routing->classification->reanalysisReason;
        $currentSource = sprintf('document:%d/sheet:%d', $documentId, $pageId);
        if ($reason === 'sheet_role_conflict') {
            if ($peer === null || $peer->source() === $currentSource) {
                return null;
            }

            return new TargetedSheetRecheckPlan(
                TargetedSheetRecheckScope::forSheetPair($role, $reason, $currentSource, $peer->source()),
                $peer,
            );
        }
        if ($reason !== 'sheet_role_insufficient_evidence') {
            return null;
        }
        $entityKey = $analysis->projectSheetAnalysis?->facts[0]['entityKey']
            ?? ($analysis->elements[0]->key ?? null);
        if (! is_string($entityKey) || $entityKey === '') {
            return null;
        }

        return new TargetedSheetRecheckPlan(
            TargetedSheetRecheckScope::forEntity($role, $reason, $entityKey, $currentSource),
            null,
        );
    }
}
