<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentUnitPageReservationPolicy
{
    /** @param array<string, mixed> $normalizedPayload */
    public function assertReservable(
        ?int $processingUnitId,
        ?string $sourceVersion,
        ?string $text,
        ?string $textHash,
        ?string $outputVersion,
        array $normalizedPayload,
        bool $hasLineage,
        int $unitId,
        string $unitSourceVersion,
    ): void {
        if ($text !== null || $textHash !== null || $outputVersion !== null || $normalizedPayload !== [] || $hasLineage) {
            throw new DocumentUnitProcessingException('unit_page_lineage_conflict');
        }
        if (($processingUnitId !== null && $processingUnitId !== $unitId)
            || ($sourceVersion !== null && $sourceVersion !== $unitSourceVersion)) {
            throw new DocumentUnitProcessingException('unit_page_reservation_conflict');
        }
    }
}
