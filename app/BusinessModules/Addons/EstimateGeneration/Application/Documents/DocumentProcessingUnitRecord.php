<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;

final class DocumentProcessingUnitRecord
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $documentId,
        public DocumentUnitData $unit,
        public DocumentProcessingUnitStatus $status = DocumentProcessingUnitStatus::Pending,
        public int $attemptCount = 0,
        public ?string $claimToken = null,
        public ?DateTimeImmutable $leaseExpiresAt = null,
        public ?string $outputVersion = null,
        public int $outputCount = 0,
        public ?string $failureCode = null,
        public ?string $failureFingerprint = null,
    ) {}
}
