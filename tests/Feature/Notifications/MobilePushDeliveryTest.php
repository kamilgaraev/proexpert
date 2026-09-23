<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Channels\MobilePushChannel;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationDeviceDelivery;
use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\BusinessModules\Features\Notifications\Services\MobileDeviceTokenService;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MobilePushDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('services.rustore.project_id', 'project-test');
        config()->set('services.rustore.service_token', 'service-token-test');
    }

    public function test_rustore_receives_android_token_and_deep_link_with_delivery_record(): void
    {
        $captured = null;
        Http::fake(function (Request $request) use (&$captured) {
            $captured = $request;

            return Http::response((object) [], 200);
        });
        $user = User::factory()->create();
        app(MobileDeviceTokenService::class)->register($user, 'android-1', 'android', 'rustore', 'rustore-token');
        $notification = $this->notification($user);

        self::assertTrue(app(MobilePushChannel::class)->send($user, $notification));
        self::assertSame('https://vkpns.rustore.ru/v1/projects/project-test/messages:send', $captured->url());
        self::assertSame('Bearer service-token-test', $captured->header('Authorization')[0]);
        self::assertSame('rustore-token', $captured->data()['message']['token']);
        self::assertSame('/site-requests/42', $captured->data()['message']['data']['route']);
        self::assertSame('17', $captured->data()['message']['data']['organization_id']);
        self::assertSame('24', $captured->data()['message']['data']['project_id']);
        self::assertSame('91', $captured->data()['message']['data']['journal_id']);
        self::assertSame('73', $captured->data()['message']['data']['warehouse_id']);
        self::assertDatabaseHas('notification_device_deliveries', [
            'notification_id' => $notification->id,
            'installation_id' => 'android-1',
            'status' => 'sent',
            'message_id' => null,
        ]);
    }

    public function test_mobile_targeted_push_is_queued_during_preference_quiet_hours(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true, 'settings' => null]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        UserRoleAssignment::assignRole($user, 'foreman', AuthorizationContext::getProjectContext($project->id, $organization->id));
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'notification_type' => 'marketing',
            'enabled_channels' => ['email'],
            'quiet_hours_start' => '00:00',
            'quiet_hours_end' => '23:59',
        ]);
        Cache::flush();
        config(['notifications.quiet_hours.apply_to_types' => ['marketing']]);
        Queue::fake();

        $notification = app(NotificationService::class)->send(
            $user,
            'mobile_announcement',
            ['title' => 'Объявление', 'message' => 'Информация', 'project_id' => $project->id],
            'marketing',
            'normal',
            organizationId: $organization->id,
            interfaces: ['mobile'],
        );

        self::assertSame(['push'], $notification->channels);
        Queue::assertPushed(SendNotificationJob::class);
    }

    public function test_only_unregistered_token_error_deletes_registration_and_safe_results_do_not_leak_token(): void
    {
        Http::fake(function (Request $request) {
            return $request->data()['message']['token'] === 'bad-token'
                ? Http::response(['error' => ['status' => 'NOT_FOUND']], 404)
                : Http::response(['error' => ['status' => 'PROJECT_NOT_FOUND']], 404);
        });
        $user = User::factory()->create();
        $tokens = app(MobileDeviceTokenService::class);
        $tokens->register($user, 'bad-phone', 'android', 'rustore', 'bad-token');
        $tokens->register($user, 'keep-phone', 'android', 'rustore', 'sensitive-token');
        $notification = $this->notification($user);

        try {
            app(MobilePushChannel::class)->send($user, $notification);
            self::fail('Expected provider failure to be retryable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('One or more mobile push deliveries failed', $exception->getMessage());
        }

        self::assertDatabaseMissing('notification_devices', ['installation_id' => 'bad-phone']);
        self::assertDatabaseHas('notification_devices', ['installation_id' => 'keep-phone']);
        self::assertSame(2, NotificationDeviceDelivery::query()->where('notification_id', $notification->id)->where('status', 'failed')->count());
        self::assertStringNotContainsString('sensitive-token', (string) NotificationDeviceDelivery::query()->where('installation_id', 'keep-phone')->value('last_error'));
    }

    private function notification(User $user): Notification
    {
        return Notification::query()->create([
            'type' => 'site_request_created',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'notification_type' => 'site_requests',
            'priority' => 'normal',
            'channels' => ['push'],
            'delivery_status' => [],
            'data' => [
                'title' => 'Новая заявка',
                'message' => 'Нужен материал',
                'organization_id' => 17,
                'project_id' => 24,
                'journal_id' => 91,
                'warehouse_id' => 73,
                'entity' => ['type' => 'site_request', 'id' => 42],
                'target_route' => '/site-requests/42',
            ],
            'metadata' => [],
        ]);
    }
}
