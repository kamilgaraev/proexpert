<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use InvalidArgumentException;

final readonly class LineageEventPage
{
    public function __construct(
        public array $events,
        public bool $hasMore,
    ) {
        if (! array_is_list($events)) {
            throw new InvalidArgumentException('lineage_event_page_invalid');
        }
        foreach ($events as $event) {
            if (! is_object($event)) {
                throw new InvalidArgumentException('lineage_event_page_invalid');
            }
        }
        if ($hasMore && $events === []) {
            throw new InvalidArgumentException('lineage_event_page_invalid');
        }
    }
}
