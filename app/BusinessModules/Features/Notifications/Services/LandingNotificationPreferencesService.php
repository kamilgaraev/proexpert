<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class LandingNotificationPreferencesService
{
    public function __construct(private readonly PreferenceManager $preferences) {}

    public function read(User $user, int $organizationId): array
    {
        $this->assertMembership($user, $organizationId);
        $stored = NotificationPreference::forUser($user->id)
            ->forOrganization($organizationId)
            ->get()
            ->keyBy('notification_type');
        $available = $this->availableChannels();
        $items = [];

        foreach (config('notifications.types', []) as $type => $settings) {
            $preference = $stored->get($type);
            $customizable = ! $settings['mandatory'] && $settings['user_customizable'];
            $channels = $customizable && $preference && is_array($preference->enabled_channels)
                ? $preference->enabled_channels
                : $settings['default_channels'];

            $items[] = [
                'notification_type' => $type,
                'name' => $settings['name'],
                'description' => $settings['description'],
                'mandatory' => (bool) $settings['mandatory'],
                'user_customizable' => (bool) $customizable,
                'enabled_channels' => array_values(array_intersect($channels, $available)),
            ];
        }

        return ['items' => $items, 'available_channels' => $available];
    }

    public function update(User $user, int $organizationId, string $type, array $channels): void
    {
        $this->assertMembership($user, $organizationId);
        $settings = config('notifications.types', [])[$type] ?? null;

        if (! is_array($settings)) {
            throw ValidationException::withMessages([
                'notification_type' => trans_message('notifications.invalid_type'),
            ]);
        }

        if ($settings['mandatory'] || ! $settings['user_customizable']) {
            throw new AuthorizationException(trans_message('notifications.not_customizable'));
        }

        if (array_diff($channels, $this->availableChannels()) !== []) {
            throw ValidationException::withMessages([
                'enabled_channels' => trans_message('notifications.validation_error'),
            ]);
        }

        $this->preferences->updatePreferences(
            $user,
            $type,
            array_values(array_unique($channels)),
            $organizationId,
        );
    }

    private function assertMembership(User $user, int $organizationId): void
    {
        if ($organizationId <= 0 || ! $user->activeOrganizations()->whereKey($organizationId)->exists()) {
            throw new AuthorizationException();
        }
    }

    private function availableChannels(): array
    {
        return array_keys(array_filter(
            config('notifications.channels', []),
            static fn (array $channel): bool => (bool) ($channel['enabled'] ?? false),
        ));
    }
}
