<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class DocumentReadinessClassifier
{
    public function requiresAction(EstimateGenerationDocument $document): bool
    {
        $status = (string) $document->status;
        if ($status === 'ignored') {
            return false;
        }
        if (in_array($status, ['failed', 'needs_review'], true)) {
            return true;
        }

        $facts = is_array($document->facts_summary) ? $document->facts_summary : [];
        $understanding = is_array($facts['document_understanding'] ?? null) ? $facts['document_understanding'] : [];
        $capabilities = is_array($understanding['extracted_capabilities'] ?? null) ? $understanding['extracted_capabilities'] : [];

        return ($understanding['role_for_estimation'] ?? null) === 'needs_review'
            || ($capabilities['requires_manual_review'] ?? false) === true;
    }

    public function actionRequiredSql(): string
    {
        return "status IN ('failed','needs_review') OR (status <> 'ignored' AND (facts_summary->'document_understanding'->>'role_for_estimation' = 'needs_review' OR facts_summary->'document_understanding'->'extracted_capabilities'->>'requires_manual_review' = 'true'))";
    }
}
