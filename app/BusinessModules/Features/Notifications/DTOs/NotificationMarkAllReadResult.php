<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\DTOs;

final readonly class NotificationMarkAllReadResult
{
    public function __construct(
        public int $count,
        public int $sequenceCut
    ) {}
}
