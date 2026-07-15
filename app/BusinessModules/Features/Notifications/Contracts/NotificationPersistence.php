<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Contracts;

use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\User;

interface NotificationPersistence
{
    public function persist(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification;
}
