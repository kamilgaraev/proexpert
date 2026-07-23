<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationSnapshotDatabase;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LaravelNotificationSnapshotDatabase implements NotificationSnapshotDatabase
{
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    public function driverName(): string
    {
        return DB::getDriverName();
    }

    public function transactionLevel(): int
    {
        return DB::transactionLevel();
    }

    public function statement(string $sql): void
    {
        if ($sql !== 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            throw new InvalidArgumentException('notification_snapshot_statement_forbidden');
        }
        DB::statement($sql);
    }
}
