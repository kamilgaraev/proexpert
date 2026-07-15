<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Contracts;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use Illuminate\Database\Eloquent\Builder;

interface NotificationMarkAllReadGateway
{
    public function markAllAsRead(
        NotificationInterface $interface,
        int $sequenceCut,
        Builder $visibleNotificationIds
    ): int;
}
