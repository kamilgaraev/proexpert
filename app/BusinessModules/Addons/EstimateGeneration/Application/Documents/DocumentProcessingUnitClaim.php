<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentProcessingUnitClaim
{
    public function __construct(
        public int $unitId,
        public DocumentProcessingUnitClaimStatus $status,
        public ?string $token = null,
        public ?\DateTimeImmutable $busyUntil = null,
        public ?int $organizationId = null,
        public ?int $projectId = null,
        public ?int $sessionId = null,
        public ?int $documentId = null,
        public ?string $sourceVersion = null,
    ) {}

    public function acquired(): bool
    {
        return $this->status === DocumentProcessingUnitClaimStatus::Acquired;
    }
}
