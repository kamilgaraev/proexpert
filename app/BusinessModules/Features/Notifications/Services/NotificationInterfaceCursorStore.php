<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class NotificationInterfaceCursorStore
{
    public function latest(User $user, NotificationInterface $interface): int
    {
        return (int) (DB::table('notification_interface_cursors')
            ->where('recipient_user_id', $user->getKey())
            ->where('interface', $interface->value)
            ->value('latest_sequence') ?? 0);
    }

    public function advance(User $user, Notification $notification): void
    {
        $timestamp = now();
        $rows = DB::table('notification_targets')
            ->where('notification_id', $notification->getKey())
            ->get(['interface', 'sequence'])
            ->map(static fn (object $target): array => [
                'recipient_user_id' => $user->getKey(),
                'interface' => $target->interface,
                'latest_sequence' => (int) $target->sequence,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        DB::table('notification_interface_cursors')->upsert(
            $rows,
            ['recipient_user_id', 'interface'],
            ['latest_sequence', 'updated_at']
        );
    }
}
