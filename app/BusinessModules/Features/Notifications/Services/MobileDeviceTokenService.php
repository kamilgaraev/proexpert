<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\NotificationDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MobileDeviceTokenService
{
    public function register(User $user, string $installationId, string $platform, string $provider, string $token): NotificationDevice
    {
        if ($platform !== 'android' || $provider !== 'rustore') {
            throw new InvalidArgumentException('RuStore push registration requires an Android device');
        }

        $tokenHash = hash('sha256', $token);

        return DB::transaction(function () use ($user, $installationId, $platform, $provider, $token, $tokenHash): NotificationDevice {
            NotificationDevice::query()
                ->where('installation_id', $installationId)
                ->where('user_id', '!=', $user->id)
                ->delete();

            NotificationDevice::query()->where('token_hash', $tokenHash)
                ->where(static function ($query) use ($user, $installationId): void {
                    $query->where('user_id', '!=', $user->id)
                        ->orWhere('installation_id', '!=', $installationId);
                })
                ->delete();

            return NotificationDevice::query()->updateOrCreate(
                ['user_id' => $user->id, 'installation_id' => $installationId],
                [
                    'platform' => $platform,
                    'provider' => $provider,
                    'token' => $token,
                    'token_hash' => $tokenHash,
                    'last_registered_at' => now(),
                ],
            );
        });
    }

    public function unregister(User $user, string $installationId): void
    {
        NotificationDevice::query()
            ->where('user_id', $user->id)
            ->where('installation_id', $installationId)
            ->delete();
    }
}
