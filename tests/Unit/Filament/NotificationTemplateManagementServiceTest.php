<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\Notifications\Services\TemplateRenderer;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Activity\ActivityEventRecorder;
use App\Services\Filament\NotificationTemplateManagementService;
use App\Services\Filament\SystemAdminAuditService;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

final class NotificationTemplateManagementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(dirname(__DIR__, 3));
        $application->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'mail' => ['from' => ['address' => 'support@example.test']],
        ]));
        $application->instance('translator', new class
        {
            public function get(string $key): string
            {
                return $key;
            }
        });
        $application->instance('log', new NullLogger);
        Facade::setFacadeApplication($application);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_suppressed_notifications_are_not_counted_as_sent_or_included_in_recipient_ids(): void
    {
        $queued = $this->notification(true, ['in_app']);
        $preferenceSuppressed = $this->notification(true, []);
        $permissionSuppressed = $this->notification(false, []);
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects(self::exactly(3))
            ->method('send')
            ->willReturnCallback(function (User $user, string $type, array $data) use (
                $queued,
                $preferenceSuppressed,
                $permissionSuppressed,
            ): Notification {
                self::assertArrayNotHasKey('force_send', $data);

                return match ((int) $user->id) {
                    10 => $queued,
                    20 => $preferenceSuppressed,
                    default => $permissionSuppressed,
                };
            });
        $service = $this->service($notificationService);

        $result = $this->sendToUserCollection($service, collect([
            $this->user(10),
            $this->user(20),
            $this->user(30),
        ]));

        self::assertSame(1, $result['sent_count']);
        self::assertSame(2, $result['suppressed_count']);
        self::assertSame([10], $result['recipient_ids']);
    }

    #[DataProvider('invalidCustomerChannels')]
    public function test_selected_broadcast_validates_channel_before_empty_recipient_validation(string $channel): void
    {
        $service = $this->service($this->createMock(NotificationService::class));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('notifications.customer_channel_unsupported'));

        $service->sendToUsers($this->template($channel), $this->systemAdmin(), []);
    }

    #[DataProvider('invalidCustomerChannels')]
    public function test_customer_channel_validator_rejects_unsupported_values(string $channel): void
    {
        $service = $this->service($this->createMock(NotificationService::class));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('notifications.customer_channel_unsupported'));

        $this->validateCustomerChannel($service, $channel);
    }

    #[DataProvider('invalidCustomerChannels')]
    public function test_all_users_broadcast_validates_channel_before_database_query(string $channel): void
    {
        $service = $this->service($this->createMock(NotificationService::class));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(trans_message('notifications.customer_channel_unsupported'));

        $service->sendToAllUsers($this->template($channel), $this->systemAdmin());
    }

    #[DataProvider('validCustomerChannels')]
    public function test_customer_channel_validator_accepts_supported_values(string $channel): void
    {
        $service = $this->service($this->createMock(NotificationService::class));

        $this->validateCustomerChannel($service, $channel);

        self::assertTrue(true);
    }

    public function test_all_users_validates_before_query_and_aggregates_suppressed_counts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Filament/NotificationTemplateManagementService.php');

        self::assertMatchesRegularExpression(
            '/function sendToAllUsers.*?assertCustomerChannelSupported.*?activeRecipientQuery/s',
            $source,
        );
        self::assertStringContainsString("\$suppressedCount += (int) \$result['suppressed_count'];", $source);
        self::assertStringContainsString("'suppressed_count' => \$suppressedCount", $source);
        self::assertMatchesRegularExpression(
            "/context: \[.*?'sent_count' =>.*?'suppressed_count' =>/s",
            $source,
        );
    }

    public static function invalidCustomerChannels(): array
    {
        return [
            'websocket' => ['websocket'],
            'empty' => [''],
            'unknown' => ['sms'],
        ];
    }

    public static function validCustomerChannels(): array
    {
        return [
            'email' => ['email'],
            'telegram' => ['telegram'],
            'in_app' => ['in_app'],
        ];
    }

    private function service(NotificationService $notificationService): NotificationTemplateManagementService
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn('Rendered message');

        return new NotificationTemplateManagementService(
            $renderer,
            $notificationService,
            new SystemAdminAuditService(
                (new ReflectionClass(ActivityEventRecorder::class))->newInstanceWithoutConstructor(),
            ),
        );
    }

    private function template(string $channel = 'in_app'): NotificationTemplate
    {
        $template = new NotificationTemplate;
        $template->forceFill([
            'id' => 5,
            'type' => 'system.test',
            'channel' => $channel,
            'name' => 'Test template',
            'subject' => null,
            'content' => 'Test content',
            'organization_id' => null,
        ]);
        $template->setRelation('organization', null);

        return $template;
    }

    private function systemAdmin(): SystemAdmin
    {
        $admin = $this->createMock(SystemAdmin::class);
        $admin->method('getRoleName')->willReturn('System admin');
        $admin->forceFill([
            'id' => 1,
            'name' => 'System admin',
            'email' => 'admin@example.test',
        ]);

        return $admin;
    }

    private function user(int $id): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => "User {$id}",
            'email' => "user{$id}@example.test",
            'current_organization_id' => null,
        ]);
        $user->setRelation('currentOrganization', null);

        return $user;
    }

    private function notification(bool $exists, array $channels): Notification
    {
        $notification = new Notification;
        $notification->forceFill(['channels' => $channels]);
        $notification->exists = $exists;

        return $notification;
    }

    private function sendToUserCollection(
        NotificationTemplateManagementService $service,
        Collection $users,
    ): array {
        $method = new ReflectionMethod($service, 'sendToUserCollection');
        $method->setAccessible(true);

        return $method->invoke($service, $this->template(), $this->systemAdmin(), $users);
    }

    private function validateCustomerChannel(NotificationTemplateManagementService $service, string $channel): void
    {
        $method = new ReflectionMethod($service, 'assertCustomerChannelSupported');
        $method->setAccessible(true);
        $method->invoke($service, $channel);
    }
}
