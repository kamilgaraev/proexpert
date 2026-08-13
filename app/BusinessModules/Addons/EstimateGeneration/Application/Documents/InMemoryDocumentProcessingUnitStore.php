<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class InMemoryDocumentProcessingUnitStore implements DocumentProcessingUnitStore
{
    private const SYSTEMIC_FAILURE_THRESHOLD = 2;

    private const SYSTEMIC_FAILURE_CODES = [
        'document_unit_processing_failed',
        'unexpected_internal_failure',
        'document_representation_contract_invalid',
        'document_representation_source_mismatch',
        'vision_provider_request_rejected',
        'vision_http_failed',
    ];

    /** @var array<int, DocumentProcessingUnitRecord> */
    private array $records = [];

    public function create(int $organizationId, int $projectId, int $sessionId, int $documentId, DocumentUnitData $unit): DocumentProcessingUnitRecord
    {
        foreach ($this->records as $record) {
            if ($record->documentId === $documentId && $record->unit->identity() === $unit->identity()) {
                return $record;
            }
        }

        return $this->records[] = new DocumentProcessingUnitRecord(
            count($this->records) + 1,
            $organizationId,
            $projectId,
            $sessionId,
            $documentId,
            $unit,
        );
    }

    public function find(int $unitId): ?DocumentProcessingUnitRecord
    {
        return $this->records[$unitId - 1] ?? null;
    }

    public function executionContext(DocumentProcessingUnitClaim $claim): ?DocumentUnitExecutionContext
    {
        $record = $this->find($claim->unitId);

        if ($record === null || ! $claim->acquired() || $record->claimToken !== $claim->token) {
            return null;
        }

        return new DocumentUnitExecutionContext(
            $record->id,
            $record->organizationId,
            $record->projectId,
            $record->sessionId,
            $record->documentId,
            $record->unit->type,
            $record->unit->index,
            $record->unit->sourceVersion,
            $record->unit->locator,
            'memory://document',
            'application/octet-stream',
            'document',
            (string) $record->claimToken,
            $record->attemptCount,
            0,
            'processing_documents',
            $record->id,
            function () use ($claim): bool {
                $now = now()->toDateTimeImmutable();

                return $this->renew(
                    $claim,
                    $now,
                    $now->modify(sprintf('+%d seconds', \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisLeasePolicy::UNIT_LEASE_SECONDS)),
                );
            },
        );
    }

    public function claim(int $unitId, string $sourceVersion, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt, int $maxAttempts): DocumentProcessingUnitClaim
    {
        $record = $this->find($unitId);

        if ($record === null || $record->unit->sourceVersion !== $sourceVersion || $record->status === DocumentProcessingUnitStatus::Superseded) {
            return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Stale);
        }

        if ($record->status === DocumentProcessingUnitStatus::Completed) {
            return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::AlreadyCompleted);
        }

        if ($record->status === DocumentProcessingUnitStatus::Running && $record->leaseExpiresAt > $now) {
            return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Busy, busyUntil: $record->leaseExpiresAt);
        }

        if ($record->attemptCount >= $maxAttempts || $leaseExpiresAt <= $now) {
            return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Exhausted);
        }

        $systemicFailureCount = $this->largestSystemicFailureGroup($record);
        if ($systemicFailureCount >= self::SYSTEMIC_FAILURE_THRESHOLD) {
            return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Exhausted);
        }
        if ($systemicFailureCount + $this->runningCount($record, $now) >= self::SYSTEMIC_FAILURE_THRESHOLD) {
            return new DocumentProcessingUnitClaim(
                $unitId,
                DocumentProcessingUnitClaimStatus::Busy,
                busyUntil: $now->modify('+5 seconds'),
            );
        }

        $record->status = DocumentProcessingUnitStatus::Running;
        $record->attemptCount++;
        $record->claimToken = (string) Str::uuid();
        $record->leaseExpiresAt = $leaseExpiresAt;
        $record->failureCode = null;
        $record->failureFingerprint = null;
        $record->failureCategory = null;

        return new DocumentProcessingUnitClaim(
            $unitId,
            DocumentProcessingUnitClaimStatus::Acquired,
            $record->claimToken,
            organizationId: $record->organizationId,
            projectId: $record->projectId,
            sessionId: $record->sessionId,
            documentId: $record->documentId,
            sourceVersion: $record->unit->sourceVersion,
        );
    }

    public function complete(DocumentProcessingUnitClaim $claim, string $outputVersion, int $outputCount, DateTimeImmutable $now): bool
    {
        $record = $this->find($claim->unitId);

        if (! $this->owns($record, $claim, $now) || $outputVersion === '' || strlen($outputVersion) > 80) {
            return false;
        }

        $record->status = DocumentProcessingUnitStatus::Completed;
        $record->outputVersion = $outputVersion;
        $record->outputCount = $outputCount;
        $record->claimToken = null;
        $record->leaseExpiresAt = null;

        return true;
    }

    public function publish(DocumentProcessingUnitClaim $claim, DocumentUnitOutput $output, DateTimeImmutable $now): bool
    {
        return $this->complete($claim, $output->version, 1, $now);
    }

    public function renew(DocumentProcessingUnitClaim $claim, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool
    {
        $record = $this->find($claim->unitId);

        if (! $this->owns($record, $claim, $now) || $leaseExpiresAt <= $now) {
            return false;
        }

        $record->leaseExpiresAt = $leaseExpiresAt;

        return true;
    }

    public function fail(
        DocumentProcessingUnitClaim $claim,
        string $code,
        string $fingerprint,
        DateTimeImmutable $now,
        FailureCategory $category = FailureCategory::Recoverable,
        bool $circuitBreaking = false,
        array $resourceUsage = [],
    ): bool {
        $record = $this->find($claim->unitId);

        if (! $this->owns($record, $claim, $now)) {
            return false;
        }

        $record->status = DocumentProcessingUnitStatus::Failed;
        $record->failureCode = $code;
        $record->failureFingerprint = $fingerprint;
        $record->failureCategory = $category;
        $record->metadata = [
            ...$record->metadata,
            'failure_category' => $category->value,
            'actual_execution_count' => $record->attemptCount,
            ...($resourceUsage === [] ? [] : ['resource_usage' => $resourceUsage]),
        ];
        $record->claimToken = null;
        $record->leaseExpiresAt = null;
        if ($category !== FailureCategory::Recoverable) {
            $record->attemptCount = ProcessDocumentUnit::MAX_ATTEMPTS;
        }

        if ($circuitBreaking && $this->matchingTerminalFailures($record, $fingerprint) >= self::SYSTEMIC_FAILURE_THRESHOLD) {
            $attemptId = $record->metadata['processing_attempt_id'] ?? null;
            foreach ($this->records as $candidate) {
                if (! $this->sameScope($candidate, $record)
                    || ($candidate->metadata['processing_attempt_id'] ?? null) !== $attemptId
                    || $candidate->status !== DocumentProcessingUnitStatus::Pending) {
                    continue;
                }

                $candidate->status = DocumentProcessingUnitStatus::Failed;
                $candidate->attemptCount = ProcessDocumentUnit::MAX_ATTEMPTS;
                $candidate->failureCode = 'breaker_stopped';
                $candidate->failureFingerprint = $fingerprint;
                $candidate->failureCategory = FailureCategory::Terminal;
                $candidate->metadata = [
                    ...$candidate->metadata,
                    'failure_category' => FailureCategory::Terminal->value,
                    'actual_execution_count' => 0,
                ];
            }
        }

        return true;
    }

    private function matchingTerminalFailures(DocumentProcessingUnitRecord $failed, string $fingerprint): int
    {
        $attemptId = $failed->metadata['processing_attempt_id'] ?? null;

        return count(array_filter(
            $this->records,
            static fn (DocumentProcessingUnitRecord $candidate): bool => $candidate->organizationId === $failed->organizationId
                && $candidate->projectId === $failed->projectId
                && $candidate->sessionId === $failed->sessionId
                && $candidate->documentId === $failed->documentId
                && $candidate->unit->sourceVersion === $failed->unit->sourceVersion
                && ($candidate->metadata['processing_attempt_id'] ?? null) === $attemptId
                && $candidate->status === DocumentProcessingUnitStatus::Failed
                && $candidate->failureFingerprint === $fingerprint,
        ));
    }

    private function largestSystemicFailureGroup(DocumentProcessingUnitRecord $scope): int
    {
        $groups = [];
        foreach ($this->records as $candidate) {
            if (! $this->sameScope($candidate, $scope)
                || $candidate->status !== DocumentProcessingUnitStatus::Failed
                || ! in_array($candidate->failureCode, self::SYSTEMIC_FAILURE_CODES, true)
                || $candidate->failureFingerprint === null) {
                continue;
            }

            $groups[$candidate->failureFingerprint] = ($groups[$candidate->failureFingerprint] ?? 0) + 1;
        }

        return $groups === [] ? 0 : max($groups);
    }

    private function runningCount(DocumentProcessingUnitRecord $scope, DateTimeImmutable $now): int
    {
        return count(array_filter(
            $this->records,
            fn (DocumentProcessingUnitRecord $candidate): bool => $this->sameScope($candidate, $scope)
                && $candidate->status === DocumentProcessingUnitStatus::Running
                && $candidate->leaseExpiresAt !== null
                && $candidate->leaseExpiresAt > $now,
        ));
    }

    private function sameScope(DocumentProcessingUnitRecord $candidate, DocumentProcessingUnitRecord $scope): bool
    {
        return $candidate->organizationId === $scope->organizationId
            && $candidate->projectId === $scope->projectId
            && $candidate->sessionId === $scope->sessionId
            && $candidate->documentId === $scope->documentId
            && $candidate->unit->sourceVersion === $scope->unit->sourceVersion
            && $candidate->unit->type === $scope->unit->type
            && ($candidate->unit->locator['content_type'] ?? null) === ($scope->unit->locator['content_type'] ?? null)
            && ($candidate->metadata['processing_attempt_id'] ?? null) === ($scope->metadata['processing_attempt_id'] ?? null);
    }

    public function supersedeDocumentSource(int $documentId, string $currentSourceVersion): void
    {
        foreach ($this->records as $record) {
            if ($record->documentId === $documentId && $record->unit->sourceVersion !== $currentSourceVersion) {
                $record->status = DocumentProcessingUnitStatus::Superseded;
                $record->claimToken = null;
                $record->leaseExpiresAt = null;
            }
        }
    }

    private function owns(?DocumentProcessingUnitRecord $record, DocumentProcessingUnitClaim $claim, DateTimeImmutable $now): bool
    {
        return $record !== null
            && $claim->acquired()
            && $record->status === DocumentProcessingUnitStatus::Running
            && $record->claimToken === $claim->token
            && $record->leaseExpiresAt !== null
            && $record->leaseExpiresAt > $now;
    }
}
