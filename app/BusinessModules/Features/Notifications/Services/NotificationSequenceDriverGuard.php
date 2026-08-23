<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use LogicException;

final class NotificationSequenceDriverGuard
{
    public static function assertSupported(string $driver): void
    {
        if ($driver === 'pgsql') {
            return;
        }

        throw new LogicException("Notification sequencing is not supported for database driver: {$driver}");
    }
}
