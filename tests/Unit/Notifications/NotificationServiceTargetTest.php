<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\BusinessModules\Features\Notifications\Contracts\NotificationCommitSequencer;
use App\BusinessModules\Features\Notifications\Contracts\NotificationPersistence;
use App\BusinessModules\Features\Notifications\DTOs\NotificationDeliveryOptions;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationPayloadNormalizer;
use App\BusinessModules\Features\Notifications\Services\NotificationRecipientPermissionResolver;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\Notifications\Services\NotificationTargetResolver;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class NotificationServiceTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $application = new Container;
        $application->instance('log', new NullLogger);
        Facade::setFacadeApplication($application);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_explicit_target_replaces_conflicting_legacy_interface_in_payload(): void
    {
        [$service, $persistence] = $this->service();

        $service->send(
            $this->user(),
            'general.notice',
            ['force_send' => true, 'interface' => 'lk'],
            notificationType: 'general',
            channels: ['in_app'],
            interfaces: ['admin'],
        );

        self::assertSame('admin', $persistence->calls[0]['data']['interface']);
    }

    public function test_multiple_targets_are_passed_to_one_persistence_call_without_scalar_interface(): void
    {
        [$service, $persistence] = $this->service();

        $service->send(
            $this->user(),
            'general.notice',
            ['force_send' => true, 'interface' => 'mobile'],
            notificationType: 'general',
            channels: ['in_app'],
            interfaces: ['admin', 'lk', 'admin'],
        );

        self::assertCount(1, $persistence->calls);
        self::assertSame(
            ['admin', 'lk'],
            array_map(
                static fn (NotificationInterface $interface): string => $interface->value,
                $persistence->calls[0]['options']->interfaces,
            ),
        );
        self::assertArrayNotHasKey('interface', $persistence->calls[0]['data']);
    }

    public function test_persistence_exception_propagates_without_dispatch(): void
    {
        $failure = new RuntimeException('Target persistence failed');
        [$service, $persistence] = $this->service(persistenceFailure: $failure);

        try {
            $service->send(
                $this->user(),
                'general.notice',
                ['force_send' => true],
                notificationType: 'general',
                channels: ['in_app'],
                interfaces: ['admin'],
            );
            self::fail('Persistence exception was not propagated');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(0, $service->dispatchCount);
        self::assertCount(1, $persistence->calls);
    }

    public function test_preference_disabled_persists_empty_channels_and_targets_without_dispatch(): void
    {
        $preferences = $this->createMock(PreferenceManager::class);
        $preferences
            ->expects(self::once())
            ->method('canSend')
            ->willReturn(false);
        [$service, $persistence] = $this->service($preferences);

        $service->send(
            $this->user(),
            'general.notice',
            [],
            notificationType: 'general',
            channels: ['websocket'],
            interfaces: ['admin', 'lk'],
        );

        self::assertSame([], $persistence->calls[0]['options']->channels);
        self::assertSame(['admin', 'lk'], $this->interfaceValues($persistence->calls[0]['options']));
        self::assertSame(0, $service->dispatchCount);
    }

    public function test_success_dispatches_once_after_persistence_returns_with_normalized_permissions(): void
    {
        $events = new NotificationTestEventLog;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')->willReturn(true);
        [$service, $persistence] = $this->service(
            authorization: $authorization,
            events: $events,
        );

        $service->send(
            $this->user(),
            'system.notice',
            ['force_send' => true],
            channels: ['websocket'],
            requiredPermissions: [' notifications.receive.system ', 'notifications.receive.system'],
            interfaces: ['admin'],
        );

        self::assertSame(['sequence_lock', 'persist', 'dispatch'], $events->entries);
        self::assertSame(1, $service->dispatchCount);
        self::assertSame(
            ['notifications.receive.system'],
            $persistence->calls[0]['options']->requiredPermissions,
        );
    }

    public function test_contract_owner_notification_is_persisted_only_for_lk_with_contract_permission(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())
            ->method('can')
            ->with(self::isInstanceOf(User::class), 'contracts.view', ['organization_id' => 42])
            ->willReturn(true);
        [$service, $persistence] = $this->service(authorization: $authorization);

        $service->send(
            $this->user(),
            'contract_status_changed',
            ['force_send' => true, 'organization_id' => 42],
            channels: ['in_app'],
            organizationId: 42,
            requiredPermissions: ['contracts.view'],
            interfaces: ['lk'],
        );

        self::assertSame(['lk'], $this->interfaceValues($persistence->calls[0]['options']));
        self::assertSame(['contracts.view'], $persistence->calls[0]['options']->requiredPermissions);
    }

    public function test_payment_notification_accepts_either_invoice_view_permission(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::exactly(2))
            ->method('can')
            ->willReturnOnConsecutiveCalls(false, true);
        [$service, $persistence] = $this->service(authorization: $authorization);

        $service->send(
            $this->user(),
            'payment.contract_excess',
            ['force_send' => true, 'organization_id' => 42],
            channels: ['in_app'],
            organizationId: 42,
            requiredPermissions: ['payments.invoice.view', 'payments.invoice.view_all'],
            interfaces: ['admin'],
        );

        self::assertSame(['admin'], $this->interfaceValues($persistence->calls[0]['options']));
        self::assertSame(
            ['payments.invoice.view', 'payments.invoice.view_all'],
            $persistence->calls[0]['options']->requiredPermissions,
        );
    }

    public function test_customer_template_notification_keeps_supported_channel_as_one_logical_notification(): void
    {
        [$service, $persistence] = $this->service();

        $service->send(
            $this->user(),
            'system.test',
            ['force_send' => true],
            notificationType: 'system_admin_broadcast',
            channels: ['in_app'],
            requiredPermissions: [],
            interfaces: ['customer'],
        );

        self::assertCount(1, $persistence->calls);
        self::assertSame(['in_app'], $persistence->calls[0]['options']->channels);
        self::assertSame(['customer'], $this->interfaceValues($persistence->calls[0]['options']));
        self::assertSame(1, $service->dispatchCount);
    }

    #[DataProvider('unsupportedWebSocketTargets')]
    public function test_websocket_rejects_unsupported_targets_before_persistence(array $interfaces): void
    {
        [$service, $persistence] = $this->service();

        $this->expectException(DomainException::class);

        try {
            $service->send(
                $this->user(),
                'general.notice',
                ['force_send' => true],
                notificationType: 'general',
                channels: ['websocket'],
                interfaces: $interfaces,
            );
        } finally {
            self::assertSame([], $persistence->calls);
            self::assertSame(0, $service->dispatchCount);
        }
    }

    public static function unsupportedWebSocketTargets(): array
    {
        return [
            'mobile' => [['mobile']],
            'customer' => [['customer']],
            'mixed supported and unsupported' => [['admin', 'mobile']],
        ];
    }

    public function test_websocket_accepts_multiple_supported_targets(): void
    {
        [$service, $persistence] = $this->service();

        $service->send(
            $this->user(),
            'general.notice',
            ['force_send' => true],
            notificationType: 'general',
            channels: ['websocket'],
            interfaces: ['admin', 'lk'],
        );

        self::assertSame(['admin', 'lk'], $this->interfaceValues($persistence->calls[0]['options']));
        self::assertSame(1, $service->dispatchCount);
    }

    public function test_permission_skipped_does_not_apply_websocket_guard_or_persist(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')->willReturn(false);
        [$service, $persistence] = $this->service(authorization: $authorization);

        $notification = $service->send(
            $this->user(),
            'system.notice',
            [],
            channels: ['websocket'],
            requiredPermissions: ['notifications.receive.system'],
            interfaces: ['admin', 'lk'],
        );

        self::assertFalse($notification->exists);
        self::assertSame([], $persistence->calls);
        self::assertSame(0, $service->dispatchCount);
    }

    public function test_database_persistence_owns_the_atomic_create_contract(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Notifications/Services/DatabaseNotificationPersistence.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString('DB::transaction(', $source);
        self::assertStringContainsString('Notification::create(', $source);
        self::assertStringContainsString("'required_permissions' => \$options->requiredPermissions", $source);
        self::assertStringContainsString('$notification->targets()->createMany(', $source);
    }

    public function test_all_runtime_target_inserts_flow_through_database_persistence(): void
    {
        $root = dirname(__DIR__, 3).'/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $targetInsertOwners = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'targets()->create') || str_contains($source, 'NotificationTarget::create')) {
                $targetInsertOwners[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        self::assertCount(1, $targetInsertOwners);
        self::assertStringEndsWith(
            '/BusinessModules/Features/Notifications/Services/DatabaseNotificationPersistence.php',
            $targetInsertOwners[0]
        );
    }

    private function service(
        ?PreferenceManager $preferences = null,
        ?Throwable $persistenceFailure = null,
        ?AuthorizationService $authorization = null,
        ?NotificationTestEventLog $events = null,
    ): array {
        $events ??= new NotificationTestEventLog;
        $persistence = new RecordingNotificationPersistence($events, $persistenceFailure);
        $authorization ??= $this->createMock(AuthorizationService::class);

        return [
            new NotificationServiceProbe(
                $preferences ?? $this->createMock(PreferenceManager::class),
                new NotificationPayloadNormalizer,
                new NotificationRecipientPermissionResolver($authorization),
                new NotificationTargetResolver,
                $persistence,
                new RecordingNotificationCommitSequencer($events),
                $events,
            ),
            $persistence,
        ];
    }

    private function user(): User
    {
        $user = new User;
        $user->forceFill([
            'id' => 777,
            'current_organization_id' => 11,
        ]);

        return $user;
    }

    private function interfaceValues(NotificationDeliveryOptions $options): array
    {
        return array_map(
            static fn (NotificationInterface $interface): string => $interface->value,
            $options->interfaces,
        );
    }
}

final class NotificationTestEventLog
{
    public array $entries = [];
}

final class RecordingNotificationPersistence implements NotificationPersistence
{
    public array $calls = [];

    public function __construct(
        private readonly NotificationTestEventLog $events,
        private readonly ?Throwable $failure = null,
    ) {}

    public function persist(
        User $user,
        string $type,
        array $data,
        string $notificationType,
        string $priority,
        NotificationDeliveryOptions $options,
    ): Notification {
        $this->calls[] = [
            'user' => $user,
            'type' => $type,
            'data' => $data,
            'notification_type' => $notificationType,
            'priority' => $priority,
            'options' => $options,
        ];
        $this->events->entries[] = 'persist';

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $notification = new Notification([
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'notification_type' => $notificationType,
            'priority' => $priority,
            'channels' => $options->channels,
            'data' => $data,
            'metadata' => ['required_permissions' => $options->requiredPermissions],
        ]);
        $notification->exists = true;

        return $notification;
    }
}

final class NotificationServiceProbe extends NotificationService
{
    public int $dispatchCount = 0;

    public function __construct(
        PreferenceManager $preferenceManager,
        NotificationPayloadNormalizer $payloadNormalizer,
        NotificationRecipientPermissionResolver $permissionResolver,
        NotificationTargetResolver $targetResolver,
        NotificationPersistence $persistence,
        NotificationCommitSequencer $commitSequencer,
        private readonly NotificationTestEventLog $events,
    ) {
        parent::__construct(
            $preferenceManager,
            $payloadNormalizer,
            $permissionResolver,
            $targetResolver,
            $persistence,
            $commitSequencer,
        );
    }

    public function dispatch(Notification $notification): void
    {
        $this->dispatchCount++;
        $this->events->entries[] = 'dispatch';
    }
}

final readonly class RecordingNotificationCommitSequencer implements NotificationCommitSequencer
{
    public function __construct(private NotificationTestEventLog $events) {}

    public function run(User $user, array $interfaces, Closure $callback): mixed
    {
        $this->events->entries[] = 'sequence_lock';

        return $callback();
    }
}
