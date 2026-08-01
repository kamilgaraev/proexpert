<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSubscriptionPage
{
    /** @param list<ReportSubscription> $items */
    public function __construct(public array $items, public ?string $nextCursor, public int $limit, public bool $hasMore)
    {
        foreach ($items as $item) {
            if (! $item instanceof ReportSubscription) {
                throw new InvalidArgumentException('report_subscription_page_items_invalid');
            }
        }
    }
}
