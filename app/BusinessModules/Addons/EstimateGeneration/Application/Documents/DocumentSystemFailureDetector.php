<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use BackedEnum;

final readonly class DocumentSystemFailureDetector
{
    public function detected(EstimateGenerationDocument $document): bool
    {
        if (in_array((string) $document->error_code, [
            'document_processing_system_failed',
            'document_processing_temporarily_unavailable',
        ], true)) {
            return true;
        }

        $facts = is_array($document->facts_summary) ? $document->facts_summary : [];
        $outcome = is_array($facts['processing_outcome'] ?? null) ? $facts['processing_outcome'] : [];
        if (in_array($outcome['type'] ?? null, ['system_failure', 'temporary_failure'], true)) {
            return true;
        }

        if (! $document->relationLoaded('processingUnits')) {
            return false;
        }
        $units = $document->processingUnits->filter(
            static fn ($unit): bool => (int) $unit->organization_id === (int) $document->organization_id
                && (int) $unit->project_id === (int) $document->project_id
                && (int) $unit->session_id === (int) $document->session_id
                && (int) $unit->document_id === (int) $document->getKey()
                && hash_equals((string) $document->source_version, (string) $unit->source_version),
        );
        if ($units->count() < 2) {
            return false;
        }

        $fingerprints = [];
        foreach ($units as $unit) {
            $status = $unit->status instanceof BackedEnum ? $unit->status->value : (string) $unit->status;
            $fingerprint = trim((string) $unit->failure_fingerprint);
            if ($status !== DocumentProcessingUnitStatus::Failed->value
                || (int) $unit->output_count !== 0
                || $fingerprint === '') {
                return false;
            }
            $fingerprints[$fingerprint] = true;
        }

        return count($fingerprints) === 1;
    }

    public function temporary(EstimateGenerationDocument $document): bool
    {
        if ((string) $document->error_code === 'document_processing_temporarily_unavailable') {
            return true;
        }

        $facts = is_array($document->facts_summary) ? $document->facts_summary : [];
        $outcome = is_array($facts['processing_outcome'] ?? null) ? $facts['processing_outcome'] : [];

        return ($outcome['type'] ?? null) === 'temporary_failure';
    }
}
