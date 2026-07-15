<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DatabaseNotificationPersistence implements NotificationPersistence
{
    public function persist(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification {
        return DB::transaction(function () use ($user, $type, $data, $notificationType, $priority, $options): Notification {
            $notification = Notification::create([
                'type' => $type,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'organization_id' => $options->organizationId,
                'notification_type' => $notificationType,
                'priority' => $priority,
                'channels' => $options->channels,
                'data' => $data,
                'delivery_status' => [],
                'metadata' => [
                    'required_permissions' => $options->requiredPermissions,
                ],
            ]);

            $notification->targets()->createMany(array_map(
                static fn (NotificationInterface $interface): array => ['interface' => $interface->value],
                $options->interfaces,
            ));

            return $notification;
        });
    }
}
