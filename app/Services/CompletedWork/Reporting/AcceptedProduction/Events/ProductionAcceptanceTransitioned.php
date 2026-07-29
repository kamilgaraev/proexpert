<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Events;

final readonly class ProductionAcceptanceTransitioned
{
    public function __construct(
        public int $eventId,
        public array $eventIds,
        public int $performanceActId,
        public string $eventType,
    ) {
    }
}
