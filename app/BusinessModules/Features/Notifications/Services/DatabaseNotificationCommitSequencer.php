<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationCommitSequencer;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class DatabaseNotificationCommitSequencer implements NotificationCommitSequencer
{
    public function run(User $user, array $interfaces, Closure $callback): mixed
    {
        $driver = DB::getDriverName();
        NotificationSequenceDriverGuard::assertSupported($driver, app()->environment('testing'));

        return DB::transaction(function () use ($user, $interfaces, $callback, $driver): mixed {
            if ($driver === 'pgsql') {
                $interfaceValues = array_map(
                    static fn (NotificationInterface $interface): string => $interface->value,
                    $interfaces
                );
                $interfaceValues = array_values(array_unique($interfaceValues));
                sort($interfaceValues, SORT_STRING);

                foreach ($interfaceValues as $interface) {
                    DB::select(
                        'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                        ["notification-target:{$user->getKey()}:{$interface}"]
                    );
                }
            }

            return $callback();
        });
    }
}
