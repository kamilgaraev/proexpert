<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExplicitDocumentRetryEligibilityTest extends TestCase
{
    #[Test]
    public function only_needs_review_document_with_typed_repairable_failure_is_allowed(): void
    {
        $eligibility = new ExplicitDocumentRetryEligibility;

        self::assertTrue($eligibility->allowed($this->document('needs_review', 'document_geometry_processing_failed')));
        self::assertFalse($eligibility->allowed($this->document('ready', 'document_geometry_processing_failed')));
        self::assertFalse($eligibility->allowed($this->document('processing', 'document_geometry_processing_failed')));
        self::assertTrue($eligibility->allowed($this->document('failed', 'document_geometry_processing_failed')));
    }

    #[Test]
    #[DataProvider('unsafeFailureCodes')]
    public function storage_integrity_and_unknown_terminal_failures_are_denied_by_default(string $failureCode): void
    {
        self::assertFalse((new ExplicitDocumentRetryEligibility)->allowed(
            $this->document('needs_review', $failureCode),
        ));
    }

    #[Test]
    public function terminal_failed_previous_retry_allows_a_new_user_lineage(): void
    {
        $document = $this->document('failed', 'document_geometry_processing_failed');
        $document->forceFill(['meta' => [
            'processing_attempt_id' => 'attempt-terminal',
            'explicit_document_retry' => [
                'attempt_id' => 'attempt-terminal',
                'source_version' => 'sha256:current',
                'status' => 'failed',
                'terminal_reason' => 'system_failure',
            ],
            'explicit_document_retry_history' => [[
                'old_attempt_id' => 'attempt-original',
                'new_attempt_id' => 'attempt-terminal',
            ]],
        ]]);

        self::assertTrue((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function active_previous_retry_blocks_a_new_user_lineage(): void
    {
        $document = $this->document('failed', 'document_geometry_processing_failed');
        $document->forceFill(['meta' => [
            'processing_attempt_id' => 'attempt-active',
            'explicit_document_retry' => [
                'attempt_id' => 'attempt-active',
                'source_version' => 'sha256:current',
                'status' => 'processing',
            ],
            'explicit_document_retry_history' => [[
                'old_attempt_id' => 'attempt-original',
                'new_attempt_id' => 'attempt-active',
            ]],
        ]]);

        self::assertFalse((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function partial_success_allows_retry_without_treating_completed_units_as_failures(): void
    {
        $document = $this->document('failed', 'document_geometry_processing_failed');
        $document->forceFill([
            'page_count' => 22,
            'processed_page_count' => 2,
            'error_code' => 'document_processing_system_failed',
        ]);
        foreach ($document->processingUnits->take(2) as $unit) {
            $unit->forceFill([
                'status' => DocumentProcessingUnitStatus::Completed,
                'output_count' => 1,
                'failure_code' => null,
                'failure_fingerprint' => null,
                'metadata' => [],
            ]);
        }

        self::assertTrue((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function vision_request_rejection_with_breaker_stops_allows_one_explicit_document_retry(): void
    {
        $document = $this->document('failed', 'vision_provider_request_rejected');
        foreach ($document->processingUnits->skip(2) as $unit) {
            $unit->forceFill(['failure_code' => 'breaker_stopped']);
        }

        self::assertTrue((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function terminal_ambiguous_units_allow_only_a_new_explicit_document_lineage(): void
    {
        self::assertTrue((new ExplicitDocumentRetryEligibility)->allowed(
            $this->document('failed', 'vision_wire_outcome_ambiguous'),
        ));
    }

    #[Test]
    public function production_document_173_failure_mix_allows_an_explicit_document_retry(): void
    {
        $failureCodes = [
            ...array_fill(0, 9, 'document_unit_pre_wire_failed'),
            ...array_fill(0, 11, 'vision_provider_response_invalid'),
            ...array_fill(0, 2, 'vision_wire_outcome_ambiguous'),
        ];
        $document = $this->document('failed', $failureCodes[0]);
        $document->forceFill([
            'page_count' => 22,
            'error_code' => 'document_processing_system_failed',
        ]);
        foreach ($document->processingUnits as $index => $unit) {
            $unit->forceFill(['failure_code' => $failureCodes[$index]]);
        }
        foreach (array_slice($failureCodes, 3) as $index => $failureCode) {
            $unit = clone $document->processingUnits->first();
            $unit->forceFill([
                'id' => $index + 4,
                'unit_index' => $index + 4,
                'failure_code' => $failureCode,
            ]);
            $document->processingUnits->push($unit);
        }

        self::assertCount(22, $document->processingUnits);
        self::assertTrue((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    #[DataProvider('activeUnitStatuses')]
    public function active_current_lineage_units_block_an_explicit_retry(DocumentProcessingUnitStatus $status): void
    {
        $document = $this->document('failed', 'vision_wire_outcome_ambiguous');
        $unit = $document->processingUnits->first();
        $unit->forceFill([
            'status' => $status,
            'failure_code' => null,
            'metadata' => [],
        ]);
        if ($status === DocumentProcessingUnitStatus::Running) {
            $unit->setRawAttributes([
                ...$unit->getAttributes(),
                'claim_token' => 'active-lease',
                'lease_expires_at' => '2099-01-01 00:00:00+00',
            ], true);
        }

        self::assertFalse((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function user_action_required_failure_blocks_an_explicit_retry(): void
    {
        $document = $this->document('failed', 'vision_wire_outcome_ambiguous');
        $document->processingUnits->first()->forceFill([
            'metadata' => ['failure_category' => 'user_action_required'],
        ]);

        self::assertFalse((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    #[Test]
    public function stale_source_units_do_not_make_a_document_retryable(): void
    {
        $document = $this->document('failed', 'vision_wire_outcome_ambiguous');
        foreach ($document->processingUnits as $unit) {
            $unit->forceFill(['source_version' => 'sha256:stale']);
        }

        self::assertFalse((new ExplicitDocumentRetryEligibility)->allowed($document));
    }

    /** @return iterable<string, array{DocumentProcessingUnitStatus}> */
    public static function activeUnitStatuses(): iterable
    {
        yield 'pending' => [DocumentProcessingUnitStatus::Pending];
        yield 'running with active lease' => [DocumentProcessingUnitStatus::Running];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeFailureCodes(): iterable
    {
        yield 'storage scope' => ['document_storage_scope_invalid'];
        yield 'artifact locator' => ['document_artifact_locator_invalid'];
        yield 'artifact content type' => ['document_artifact_content_type_invalid'];
        yield 'reused storage path' => ['document_reuse_storage_path_invalid'];
        yield 'unknown terminal failure' => ['document_unknown_terminal_failure'];
    }

    private function document(string $status, string $failureCode): EstimateGenerationDocument
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 168,
            'organization_id' => 38,
            'project_id' => 52,
            'session_id' => 66,
            'status' => $status,
            'source_version' => 'sha256:current',
            'page_count' => 3,
            'processed_page_count' => 0,
            'meta' => [],
        ]);
        $fingerprint = hash('sha256', 'same-root');
        $units = [];
        foreach (range(1, 3) as $index) {
            $unit = new EstimateGenerationProcessingUnit;
            $unit->forceFill([
                'id' => $index,
                'organization_id' => 38,
                'project_id' => 52,
                'session_id' => 66,
                'document_id' => 168,
                'source_version' => 'sha256:current',
                'unit_type' => DocumentUnitType::PdfPage,
                'unit_index' => $index,
                'status' => DocumentProcessingUnitStatus::Failed,
                'output_count' => 0,
                'failure_code' => $failureCode,
                'failure_fingerprint' => $fingerprint,
                'metadata' => ['failure_category' => 'terminal'],
            ]);
            $units[] = $unit;
        }
        $document->setRelation('processingUnits', new Collection($units));

        return $document;
    }
}
