<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\Models\User;
use DomainException;
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
        NotificationSequenceDriverGuard::assertSupported($driver);

        return DB::transaction(function () use ($user, $type, $data, $notificationType, $priority, $options, $driver): Notification {
            $notification = $options->notificationId === null
                ? null
                : Notification::query()->whereKey($options->notificationId)->lockForUpdate()->first();
            if ($notification !== null && (
                $notification->notifiable_type !== User::class
                || (int) $notification->notifiable_id !== (int) $user->id
                || $notification->organization_id !== $options->organizationId
                || $notification->type !== $type
            )) {
                throw new DomainException('notification_identifier_conflict');
            }
            $notification ??= new Notification;
            if ($options->notificationId !== null) {
                $notification->setAttribute('id', $options->notificationId);
            }
            $notification->fill([
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
            $notification->save();

            $nextSequence = $driver === 'pgsql'
                ? null
                : ((int) NotificationTarget::query()->max('sequence')) + 1;
            $existingInterfaces = $notification->targets()->toBase()->pluck('interface')->all();
            $missingInterfaces = array_values(array_filter(
                $options->interfaces,
                static fn (NotificationInterface $interface): bool => ! in_array($interface->value, $existingInterfaces, true),
            ));
            $targets = array_map(
                static fn (NotificationInterface $interface, int $index): array => [
                    'interface' => $interface->value,
                    ...($nextSequence === null ? [] : ['sequence' => $nextSequence + $index]),
                ],
                $missingInterfaces,
                array_keys($missingInterfaces),
            );
            if ($targets !== []) {
                $notification->targets()->createMany($targets);
                $this->cursorStore->advance($user, $notification);
            }

            return $notification;
        });
    }
}
