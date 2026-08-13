<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSystemFailureDetector;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final readonly class DocumentReadinessClassifier
{
    public function __construct(
        private DocumentSystemFailureDetector $systemFailures = new DocumentSystemFailureDetector,
    ) {}

    public function requiresAction(EstimateGenerationDocument $document): bool
    {
        $status = (string) $document->status;
        if ($status === 'ignored') {
            return false;
        }
        if ($this->systemFailures->detected($document)) {
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
        $legacySystemicFailure = <<<'SQL'
EXISTS (
    SELECT 1
    FROM estimate_generation_processing_units AS systemic_units
    WHERE systemic_units.organization_id = estimate_generation_documents.organization_id
      AND systemic_units.project_id = estimate_generation_documents.project_id
      AND systemic_units.session_id = estimate_generation_documents.session_id
      AND systemic_units.document_id = estimate_generation_documents.id
      AND systemic_units.source_version = estimate_generation_documents.source_version
    GROUP BY systemic_units.failure_fingerprint
    HAVING COUNT(*) >= 2
       AND COUNT(*) = (
           SELECT COUNT(*)
           FROM estimate_generation_processing_units AS current_units
           WHERE current_units.organization_id = estimate_generation_documents.organization_id
             AND current_units.project_id = estimate_generation_documents.project_id
             AND current_units.session_id = estimate_generation_documents.session_id
             AND current_units.document_id = estimate_generation_documents.id
             AND current_units.source_version = estimate_generation_documents.source_version
       )
       AND BOOL_AND(
           systemic_units.status = 'failed'
           AND systemic_units.output_count = 0
           AND NULLIF(BTRIM(systemic_units.failure_fingerprint), '') IS NOT NULL
       )
)
SQL;
        $canonicalAction = "(status = 'failed' AND COALESCE(error_code, '') NOT IN ('document_processing_system_failed','document_processing_temporarily_unavailable')) OR status = 'needs_review' OR (status <> 'ignored' AND (facts_summary->'document_understanding'->>'role_for_estimation' = 'needs_review' OR facts_summary->'document_understanding'->'extracted_capabilities'->>'requires_manual_review' = 'true'))";

        return sprintf('(%s) AND NOT (%s)', $canonicalAction, $legacySystemicFailure);
    }
}
