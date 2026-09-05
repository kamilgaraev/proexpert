<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\Models\User;
use Mockery;
use Tests\TestCase;

class PreferenceManagerChannelsTest extends TestCase
{
    public function test_explicit_empty_channels_disable_optional_delivery(): void
    {
        $this->configureType();
        $manager = $this->managerWithChannels([]);

        $this->assertSame([], $manager->getChannels(new User(), 'test_optional'));
    }

    public function test_unset_channels_keep_default_delivery(): void
    {
        $this->configureType();
        $manager = $this->managerWithChannels(null);

        $this->assertSame(['email'], $manager->getChannels(new User(), 'test_optional'));
    }

    public function test_disabled_platform_channels_are_not_selected(): void
    {
        $this->configureType();
        $manager = $this->managerWithChannels(['email', 'telegram']);

        $this->assertSame(['email'], $manager->getChannels(new User(), 'test_optional'));
    }

    public function test_mandatory_notifications_ignore_empty_preferences(): void
    {
        $this->configureType();
        config(['notifications.types.test_optional.mandatory' => true]);
        $manager = Mockery::mock(PreferenceManager::class)->makePartial();
        $manager->shouldNotReceive('getPreference');

        $this->assertSame(['email'], $manager->getChannels(new User(), 'test_optional'));
    }

    private function configureType(): void
    {
        config([
            'notifications.types.test_optional' => [
                'mandatory' => false,
                'user_customizable' => true,
                'default_channels' => ['email'],
            ],
            'notifications.channels' => [
                'email' => ['enabled' => true],
                'telegram' => ['enabled' => false],
            ],
        ]);
    }

    private function managerWithChannels(?array $channels): PreferenceManager
    {
        $preference = new NotificationPreference(['enabled_channels' => $channels]);
        $manager = Mockery::mock(PreferenceManager::class)->makePartial();
        $manager->shouldReceive('getPreference')->once()->andReturn($preference);

        return $manager;
    }
}
