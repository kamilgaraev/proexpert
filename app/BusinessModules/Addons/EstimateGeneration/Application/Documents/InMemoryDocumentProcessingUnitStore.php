<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;
use Illuminate\Support\Str;

final class InMemoryDocumentProcessingUnitStore implements DocumentProcessingUnitStore
{
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

        $record->status = DocumentProcessingUnitStatus::Running;
        $record->attemptCount++;
        $record->claimToken = (string) Str::uuid();
        $record->leaseExpiresAt = $leaseExpiresAt;
        $record->failureCode = null;
        $record->failureFingerprint = null;

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

    public function fail(DocumentProcessingUnitClaim $claim, string $code, string $fingerprint, DateTimeImmutable $now): bool
    {
        $record = $this->find($claim->unitId);

        if (! $this->owns($record, $claim, $now)) {
            return false;
        }

        $record->status = DocumentProcessingUnitStatus::Failed;
        $record->failureCode = $code;
        $record->failureFingerprint = $fingerprint;
        $record->claimToken = null;
        $record->leaseExpiresAt = null;

        return true;
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
