<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Contracts;

use App\Models\User;
use Closure;

interface NotificationCommitSequencer
{
    public function run(User $user, array $interfaces, Closure $callback): mixed;
}
