<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Models\NotificationPreference;
use App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class AdminNotificationPreferencesReadTest extends TestCase
{
    public function test_unexpected_read_error_returns_safe_admin_response_and_logs_only_safe_context(): void
    {
        Queue::fake(); Notification::fake(); config(['cache.default' => 'array']);
        $context = Model::withoutEvents(fn () => AdminApiTestContext::create());
        $logged = [];
        Log::listen(static function ($event) use (&$logged): void {
            if ($event->level === 'error' && $event->message === 'Admin notification preferences load failed') {
                $logged[] = $event->context;
            }
        });
        $failRead = true;
        DB::listen(static function ($query) use (&$failRead): void {
            if ($failRead && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'notification_preferences')) {
                $failRead = false;
                throw new \RuntimeException('private-notification-diagnostic335');
            }
        });
        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/notifications/preferences');
        $response->assertStatus(500)->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('notification_preferences.load_error'));
        self::assertFalse($failRead);
        self::assertStringNotContainsString('private-notification-diagnostic335', $response->getContent());
        self::assertCount(1, $logged);
        self::assertSame($context->user->id, $logged[0]['user_id']);
        self::assertSame($context->organization->id, $logged[0]['organization_id']);
        self::assertIsString($logged[0]['exception_class']);
        self::assertStringNotContainsString('private-notification-diagnostic335', json_encode($logged));
    }

    public function test_admin_origin_reads_only_own_current_organization_preferences_without_writes(): void
    {
        Queue::fake(); Notification::fake(); config(['cache.default' => 'array']);
        $context = Model::withoutEvents(fn () => AdminApiTestContext::create());
        $foreign = Model::withoutEvents(fn () => AdminApiTestContext::create());
        config([
            'notifications.channels' => ['email' => ['enabled' => true], 'in_app' => ['enabled' => true], 'websocket' => ['enabled' => false]],
            'notifications.types' => [
                'custom' => ['name' => 'Личные события', 'description' => 'Тест', 'mandatory' => false, 'user_customizable' => true, 'default_channels' => ['in_app']],
                'fixed' => ['name' => 'Обязательные события', 'description' => 'Тест', 'mandatory' => true, 'user_customizable' => true, 'default_channels' => ['in_app', 'websocket']],
            ],
        ]);
        $native = app(LandingNotificationPreferencesService::class);
        $native->update($context->user, $context->organization->id, 'custom', ['email']);
        $native->update($foreign->user, $foreign->organization->id, 'custom', ['in_app']);
        $count = NotificationPreference::query()->count();
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/notifications/preferences')
            ->assertOk()->assertJsonPath('success', true)
            ->assertExactJson(['success' => true, 'message' => null, 'data' => $native->read($context->user, $context->organization->id)]);
        self::assertSame($count, NotificationPreference::query()->count());
        $this->getJson('/api/v1/admin/notifications/preferences?user_id='.$foreign->user->id)->assertUnprocessable();
        $this->getJson('/api/v1/admin/notifications/preferences?organization_id='.$foreign->organization->id)->assertUnprocessable();
        config(['notifications.channels.websocket.enabled' => true]);
        $this->getJson('/api/v1/admin/notifications/preferences')->assertOk()
            ->assertJsonPath('data.items.1.enabled_channels', ['in_app', 'websocket'])
            ->assertJsonPath('data.items.1.user_customizable', false);
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['is_active' => false]);
        $this->getJson('/api/v1/admin/notifications/preferences')->assertForbidden();
    }
}
