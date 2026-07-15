<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Contracts;

use Closure;

interface NotificationSnapshotDatabase
{
    public function transaction(Closure $callback): mixed;

    public function driverName(): string;

    public function transactionLevel(): int;

    public function statement(string $sql): void;
}
