<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\DTO;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;

final readonly class AcceptedProductionUniverseEntry
{
    public function __construct(
        public array $candidate,
        public array $events,
    ) {}

    public function latestEvent(): ?ProductionAcceptanceEvent
    {
        $event = $this->events[array_key_last($this->events)] ?? null;

        return $event instanceof ProductionAcceptanceEvent ? $event : null;
    }
}
