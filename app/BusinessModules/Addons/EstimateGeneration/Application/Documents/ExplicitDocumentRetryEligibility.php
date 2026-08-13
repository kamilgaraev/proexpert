<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use BackedEnum;

final readonly class ExplicitDocumentRetryEligibility
{
    private const RETRYABLE_DOCUMENT_STATUSES = [
        'needs_review',
        'failed',
    ];

    private const REPAIRABLE_FAILURE_CODES = [
        'document_geometry_processing_failed',
        'document_representation_contract_invalid',
        'document_representation_measurement_invalid',
        'document_unit_processing_failed',
    ];

    private const BREAKER_FAILURE_CODES = [
        'document_systemic_failure',
    ];

    public function __construct(
        private DocumentSystemFailureDetector $systemFailures = new DocumentSystemFailureDetector,
    ) {}

    public function allowed(EstimateGenerationDocument $document): bool
    {
        $meta = is_array($document->meta) ? $document->meta : [];
        if (! in_array((string) $document->status, self::RETRYABLE_DOCUMENT_STATUSES, true)) {
            return false;
        }

        $currentRetry = is_array($meta['explicit_document_retry'] ?? null)
            ? $meta['explicit_document_retry']
            : [];
        if (! $this->terminalRetryAllowsNewLineage($currentRetry, $meta, (string) $document->source_version)) {
            return false;
        }

        if (trim((string) $document->source_version) === ''
            || ! $this->systemFailures->detected($document)
            || $this->systemFailures->temporary($document)) {
            return false;
        }

        if (! $document->relationLoaded('processingUnits')) {
            return false;
        }

        $hasCurrentUnits = false;
        $hasRepairableFailure = false;
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
                || ! in_array((string) $unit->failure_code, [
                    ...self::REPAIRABLE_FAILURE_CODES,
                    ...self::BREAKER_FAILURE_CODES,
                ], true)) {
                return false;
            }
            $hasRepairableFailure = $hasRepairableFailure
                || in_array((string) $unit->failure_code, self::REPAIRABLE_FAILURE_CODES, true);
        }

        return $hasCurrentUnits
            && $hasRepairableFailure
            && (int) $document->processed_page_count === 0;
    }

    /** @param array<string, mixed> $currentRetry @param array<string, mixed> $meta */
    private function terminalRetryAllowsNewLineage(array $currentRetry, array $meta, string $sourceVersion): bool
    {
        if ($currentRetry === []) {
            return true;
        }

        $retrySourceVersion = (string) ($currentRetry['source_version'] ?? '');
        $retryAttemptId = (string) ($currentRetry['attempt_id'] ?? '');
        $processingAttemptId = (string) ($meta['processing_attempt_id'] ?? '');

        return $retrySourceVersion !== ''
            && hash_equals($sourceVersion, $retrySourceVersion)
            && $retryAttemptId !== ''
            && hash_equals($processingAttemptId, $retryAttemptId)
            && ($currentRetry['status'] ?? null) === 'failed'
            && ($currentRetry['terminal_reason'] ?? null) === 'system_failure';
    }
}
