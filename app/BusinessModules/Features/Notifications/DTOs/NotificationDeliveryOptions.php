<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\DTOs;

final readonly class NotificationDeliveryOptions
{
    public function __construct(
        public array $channels,
        public array $interfaces,
        public ?int $organizationId,
        public array $requiredPermissions,
    ) {}
}
