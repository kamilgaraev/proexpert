<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DatabaseNotificationPersistence implements NotificationPersistence
{
    public function __construct(private readonly NotificationInterfaceCursorStore $cursorStore) {}

    public function persist(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification {
        $driver = DB::getDriverName();
        NotificationSequenceDriverGuard::assertSupported($driver, app()->environment('testing'));

        return DB::transaction(function () use ($user, $type, $data, $notificationType, $priority, $options, $driver): Notification {
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

            $nextSequence = $driver === 'pgsql'
                ? null
                : ((int) NotificationTarget::query()->max('sequence')) + 1;
            $targets = array_map(
                static fn (NotificationInterface $interface, int $index): array => [
                    'interface' => $interface->value,
                    ...($nextSequence === null ? [] : ['sequence' => $nextSequence + $index]),
                ],
                $options->interfaces,
                array_keys($options->interfaces),
            );
            $notification->targets()->createMany($targets);
            $this->cursorStore->advance($user, $notification);

            return $notification;
        });
    }
}
