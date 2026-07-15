<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\DTOs;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class NotificationListSnapshot
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $notifications
     * @param array{
     *     count: int,
     *     by_category: array<string, int>,
     *     by_notification_type: array<string, int>,
     *     by_type: array<string, int>
     * } $unreadAggregates
     */
    public function __construct(
        public LengthAwarePaginator $notifications,
        public array $unreadAggregates
    ) {}
}
