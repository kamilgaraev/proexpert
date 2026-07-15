<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationMarkAllReadGateway;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseNotificationMarkAllReadGateway implements NotificationMarkAllReadGateway
{
    public function markAllAsRead(
        NotificationInterface $interface,
        int $sequenceCut,
        Builder $visibleNotificationIds
    ): int {
        return NotificationTarget::query()
            ->where('interface', $interface->value)
            ->where('sequence', '<=', $sequenceCut)
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->whereIn('notification_id', $visibleNotificationIds)
            ->update(['read_at' => now()]);
    }
}
