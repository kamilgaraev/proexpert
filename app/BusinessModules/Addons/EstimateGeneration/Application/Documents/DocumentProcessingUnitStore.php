<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;

interface DocumentProcessingUnitStore
{
    public function create(int $organizationId, int $projectId, int $sessionId, int $documentId, DocumentUnitData $unit): DocumentProcessingUnitRecord;

    public function find(int $unitId): ?DocumentProcessingUnitRecord;

    public function executionContext(DocumentProcessingUnitClaim $claim): ?DocumentUnitExecutionContext;

    public function claim(int $unitId, string $sourceVersion, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt, int $maxAttempts): DocumentProcessingUnitClaim;

    public function complete(DocumentProcessingUnitClaim $claim, string $outputVersion, int $outputCount, DateTimeImmutable $now): bool;

    public function publish(DocumentProcessingUnitClaim $claim, DocumentUnitOutput $output, DateTimeImmutable $now): bool;

    public function renew(DocumentProcessingUnitClaim $claim, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool;

    public function fail(DocumentProcessingUnitClaim $claim, string $code, string $fingerprint, DateTimeImmutable $now): bool;

    public function supersedeDocumentSource(int $documentId, string $currentSourceVersion): void;
}
