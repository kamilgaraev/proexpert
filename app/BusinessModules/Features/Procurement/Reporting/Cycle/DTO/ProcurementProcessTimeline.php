<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use InvalidArgumentException;

final readonly class ProcurementProcessTimeline
{
    /** @param list<ProcurementProcessEvent> $events */
    public function __construct(
        public array $events,
    ) {
        if ($events === []) {
            throw new InvalidArgumentException('Procurement process timeline cannot be empty.');
        }
    }

    /** @param list<ProcurementProcessEvent> $events */
    public static function fromEvents(array $events): self
    {
        return new self($events);
    }
}
