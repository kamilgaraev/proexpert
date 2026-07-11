<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentUnitPageReservationPolicy
{
    public function assertReservable(
        DocumentUnitPageReservationState $state,
        int $unitId,
        string $unitSourceVersion,
    ): void {
        if (! $state->pristine()) {
            throw new DocumentUnitProcessingException('unit_page_lineage_conflict');
        }
        if (($state->processingUnitId !== null && $state->processingUnitId !== $unitId)
            || ($state->sourceVersion !== null && $state->sourceVersion !== $unitSourceVersion)) {
            throw new DocumentUnitProcessingException('unit_page_reservation_conflict');
        }
    }
}
