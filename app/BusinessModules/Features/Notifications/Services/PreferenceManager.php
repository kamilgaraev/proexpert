<?php

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PreferenceManager
{
    public function getChannels(User $user, string $notificationType, ?int $organizationId = null): array
    {
        $typeConfig = config("notifications.types.{$notificationType}");

        if (!$typeConfig) {
            return config('notifications.default_channels', ['in_app']);
        }

        if ($typeConfig['mandatory'] || !$typeConfig['user_customizable']) {
            return $typeConfig['default_channels'];
        }

        $preference = $this->getPreference($user, $notificationType, $organizationId);

        if ($preference && !empty($preference->enabled_channels)) {
            return array_intersect(
                $preference->enabled_channels,
                $this->getAvailableChannels()
            );
        }

        return $typeConfig['default_channels'];
    }

    public function getPreference(User $user, string $notificationType, ?int $organizationId = null): ?NotificationPreference
    {
        $cacheKey = "notification_pref:{$user->id}:{$notificationType}:{$organizationId}";

        return Cache::remember($cacheKey, 3600, function () use ($user, $notificationType, $organizationId) {
            return NotificationPreference::forUser($user->id)
                ->forOrganization($organizationId)
                ->byType($notificationType)
                ->first();
        });
    }

    public function updatePreferences(User $user, string $notificationType, array $channels, ?int $organizationId = null): NotificationPreference
    {
        $preference = NotificationPreference::updateOrCreate(
            [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'notification_type' => $notificationType,
            ],
            [
                'enabled_channels' => $channels,
            ]
        );

        $this->clearCache($user, $notificationType, $organizationId);

        return $preference;
    }

    public function canSend(User $user, string $notificationType, ?int $organizationId = null, ?Carbon $time = null): bool
    {
        $time = $time ?? now();

        $typeConfig = config("notifications.types.{$notificationType}");

        if ($typeConfig && $typeConfig['mandatory']) {
            return true;
        }

        $preference = $this->getPreference($user, $notificationType, $organizationId);

        if ($preference && $preference->isInQuietHours($time)) {
            $quietHoursTypes = config('notifications.quiet_hours.apply_to_types', []);

            if (in_array($notificationType, $quietHoursTypes)) {
                return false;
            }
        }

        if (config('notifications.rate_limiting.enabled')) {
            return $this->checkRateLimit($user, $notificationType);
        }

        return true;
    }

    protected function checkRateLimit(User $user, string $notificationType): bool
    {
        $maxPerHour = config('notifications.rate_limiting.max_per_hour', 100);
        $maxPerDay = config('notifications.rate_limiting.max_per_day', 500);

        $hourKey = "rate_limit:{$user->id}:{$notificationType}:hour:" . now()->format('YmdH');
        $dayKey = "rate_limit:{$user->id}:{$notificationType}:day:" . now()->format('Ymd');

        $hourCount = Cache::get($hourKey, 0);
        $dayCount = Cache::get($dayKey, 0);

        if ($hourCount >= $maxPerHour || $dayCount >= $maxPerDay) {
            return false;
        }

        Cache::increment($hourKey, 1);
        Cache::put($hourKey, $hourCount + 1, now()->addHour());

        Cache::increment($dayKey, 1);
        Cache::put($dayKey, $dayCount + 1, now()->addDay());

        return true;
    }

    protected function getAvailableChannels(): array
    {
        return array_keys(array_filter(
            config('notifications.channels', []),
            fn($channel) => $channel['enabled'] ?? false
        ));
    }

    protected function clearCache(User $user, string $notificationType, ?int $organizationId = null): void
    {
        $cacheKey = "notification_pref:{$user->id}:{$notificationType}:{$organizationId}";
        Cache::forget($cacheKey);
    }
}

