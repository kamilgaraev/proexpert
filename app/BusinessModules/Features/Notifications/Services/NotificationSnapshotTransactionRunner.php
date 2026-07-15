<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationSnapshotDatabase;
use Closure;
use LogicException;

final readonly class NotificationSnapshotTransactionRunner
{
    public function __construct(
        private NotificationSnapshotDatabase $database
    ) {}

    public function run(Closure $callback): mixed
    {
        return $this->database->transaction(function () use ($callback): mixed {
            if ($this->database->driverName() === 'pgsql') {
                if ($this->database->transactionLevel() !== 1) {
                    throw new LogicException(
                        'Notification snapshot requires an outermost PostgreSQL transaction'
                    );
                }

                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        });
    }
}
