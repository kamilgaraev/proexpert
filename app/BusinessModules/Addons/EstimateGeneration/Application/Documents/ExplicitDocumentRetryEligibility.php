<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use BackedEnum;

final readonly class ExplicitDocumentRetryEligibility
{
    private const FORBIDDEN_FAILURE_MARKERS = [
        'integrity',
        'security',
        'corrupt',
        'hard_limit',
        'limit_exceeded',
        'too_large',
        'unsupported',
    ];

    public function __construct(
        private DocumentSystemFailureDetector $systemFailures = new DocumentSystemFailureDetector,
    ) {}

    public function allowed(EstimateGenerationDocument $document): bool
    {
        $meta = is_array($document->meta) ? $document->meta : [];
        if (is_array($meta['explicit_document_retry_history'] ?? null)
            && $meta['explicit_document_retry_history'] !== []) {
            return false;
        }

        if (trim((string) $document->source_version) === ''
            || ! $this->systemFailures->detected($document)
            || $this->systemFailures->temporary($document)) {
            return false;
        }

        if ($this->forbiddenFailure((string) $document->error_code)) {
            return false;
        }

        if (! $document->relationLoaded('processingUnits')) {
            return true;
        }

        $hasCurrentUnits = false;
        foreach ($document->processingUnits as $unit) {
            if (! hash_equals((string) $document->source_version, (string) $unit->source_version)) {
                continue;
            }

            $hasCurrentUnits = true;
            $status = $unit->status instanceof BackedEnum ? $unit->status->value : (string) $unit->status;
            $metadata = is_array($unit->metadata) ? $unit->metadata : [];
            if (in_array($status, [
                DocumentProcessingUnitStatus::Pending->value,
                DocumentProcessingUnitStatus::Running->value,
            ], true)
                || ($metadata['failure_category'] ?? null) === 'user_action_required'
                || $this->forbiddenFailure((string) $unit->failure_code)) {
                return false;
            }
        }

        return ! $hasCurrentUnits || (int) $document->processed_page_count === 0;
    }

    private function forbiddenFailure(string $code): bool
    {
        foreach (self::FORBIDDEN_FAILURE_MARKERS as $marker) {
            if (str_contains($code, $marker)) {
                return true;
            }
        }

        return false;
    }
}
