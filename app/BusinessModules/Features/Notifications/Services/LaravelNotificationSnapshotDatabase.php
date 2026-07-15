<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationSnapshotDatabase;
use Closure;
use Illuminate\Support\Facades\DB;

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
        DB::statement($sql);
    }
}
