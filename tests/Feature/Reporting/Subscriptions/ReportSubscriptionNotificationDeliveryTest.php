<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionDeliveryProcessor;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Notifications\PersistedInAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionStore;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\TestCase;

#[Group('postgres-contract')]
final class ReportSubscriptionNotificationDeliveryTest extends TestCase
{
    public function test_receipt_waits_for_actual_in_app_delivery_and_retries_reuse_notification(): void
    {
        [$context, $subscription, $delivery, $export] = $this->fixture();
        $notifier = app(PersistedInAppReportSubscriptionNotifier::class);
        $key = $delivery->notificationIdempotencyKey();

        self::assertNull($notifier->notify($context, $subscription, $delivery, $export, $key));
        self::assertNull($notifier->notify($context, $subscription, $delivery, $export, $key));
        self::assertSame(1, Notification::query()->count());
        self::assertSame(1, DB::table('report_subscription_notification_receipts')->count());
        $notification = Notification::query()->sole();
        self::assertSame(['in_app'], $notification->channels);
        self::assertSame('admin', $notification->targets()->sole()->interface->value);

        (new SendNotificationJob($notification))->handle(app(NotificationService::class));

        $receipt = $notifier->notify($context, $subscription, $delivery, $export, $key);
        self::assertNotNull($receipt);
        self::assertSame($receipt->id, $notifier->notify($context, $subscription, $delivery, $export, $key)?->id);
        self::assertSame('sent', $notification->fresh()->delivery_status['in_app']);
        self::assertSame(1, $notification->analytics()->where('channel', 'in_app')->where('status', 'delivered')->count());
        self::assertSame(1, Notification::query()->count());
    }

    public function test_rate_limit_does_not_create_a_success_receipt_or_orphan_notification(): void
    {
        [$context, $subscription, $delivery, $export] = $this->fixture();
        $preferences = $this->createMock(PreferenceManager::class);
        $preferences->method('canSend')->willReturn(false);
        $this->app->instance(PreferenceManager::class, $preferences);
        $notifier = app(PersistedInAppReportSubscriptionNotifier::class);

        self::assertNull($notifier->notify($context, $subscription, $delivery, $export, $delivery->notificationIdempotencyKey()));

        self::assertSame(0, DB::table('report_subscription_notification_receipts')->count());
        self::assertSame(0, Notification::query()->count());
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_recipient_without_report_permission_cannot_receive_a_receipt(): void
    {
        [$context, $subscription, $delivery, $export] = $this->fixture(false);
        $notifier = app(PersistedInAppReportSubscriptionNotifier::class);

        self::assertNull($notifier->notify($context, $subscription, $delivery, $export, $delivery->notificationIdempotencyKey()));

        self::assertSame(0, DB::table('report_subscription_notification_receipts')->count());
        self::assertSame(0, Notification::query()->count());
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_processor_keeps_ready_until_worker_delivery_without_rebuilding_export(): void
    {
        $fixture = $this->fixture();
        [, , $delivery] = $fixture;
        $processor = $this->processor($fixture, 1, true);

        $processor->process($delivery->id);
        $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'ready', 'attempt' => 0, 'notified_at' => null]);
        (new SendNotificationJob(Notification::query()->sole()))->handle(app(NotificationService::class));
        $processor->process($delivery->id);
        $processor->process($delivery->id);
        $this->processor($fixture, 0, false, true)->process($delivery->id);

        $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'notified', 'attempt' => 0]);
        self::assertSame(1, Notification::query()->count());
        self::assertSame(1, DB::table('notification_analytics')->where('channel', 'in_app')->where('status', 'delivered')->count());
    }

    #[DataProvider('unavailableChannels')]
    public function test_processor_preserves_ready_export_when_notifications_are_unavailable(bool $accessDenied): void
    {
        $fixture = $this->fixture();
        [, $subscription, $delivery] = $fixture;
        if (! $accessDenied) {
            $this->mock(PreferenceManager::class)->shouldReceive('canSend')->andReturn(false);
        }
        $processor = $this->processor($fixture, 2, false, $accessDenied);

        $processor->process($delivery->id);
        $processor->process($delivery->id);

        $this->assertDatabaseHas('report_subscription_deliveries', [
            'id' => $delivery->id, 'status' => 'ready', 'attempt' => 0,
            'run_id' => $delivery->runId, 'export_id' => $delivery->exportId,
            'notified_at' => null, 'notification_receipt_id' => null,
        ]);
        $this->assertDatabaseHas('report_subscriptions', ['id' => $subscription->id, 'consecutive_failures' => 0]);
        self::assertSame(0, Notification::query()->count());
        self::assertSame(0, DB::table('report_subscription_notification_receipts')->count());
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public static function unavailableChannels(): array
    {
        return [[false], [true]];
    }

    public function test_event_recording_failure_rolls_back_notified_and_can_be_retried(): void
    {
        $fixture = $this->fixture();
        [$context, $subscription, $delivery, $export] = $fixture;
        app(PersistedInAppReportSubscriptionNotifier::class)->notify(
            $context, $subscription, $delivery, $export, $delivery->notificationIdempotencyKey(),
        );
        (new SendNotificationJob(Notification::query()->sole()))->handle(app(NotificationService::class));

        try {
            $this->processor($fixture, 0, true, false, true)->process($delivery->id);
            self::fail('The event recording failure must roll back the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('event_recorder_unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('report_subscription_deliveries', [
            'id' => $delivery->id, 'status' => 'ready', 'notified_at' => null, 'notification_receipt_id' => null,
        ]);
        $this->processor($fixture, 0, true)->process($delivery->id);
        $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'notified']);
        self::assertSame(1, Notification::query()->count());
    }

    #[DataProvider('unavailableChannels')]
    public function test_ready_delivery_uses_frozen_report_project_timezone_and_current_access(bool $accessDenied): void
    {
        $fixture = $this->fixture(scoped: true);
        [, $subscription, $delivery, , $definition] = $fixture;
        DB::table('report_subscriptions')->where('id', $subscription->id)->update(['report_code' => 'new_report', 'timezone' => 'UTC']);
        $processor = $this->processor($fixture, 1, ! $accessDenied, $accessDenied);
        $processor->process($delivery->id);
        $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'ready']);
        if ($accessDenied) {
            self::assertSame(0, Notification::query()->count());
            self::assertSame(0, DB::table('report_subscription_notification_receipts')->count());

            return;
        }
        $notification = Notification::query()->sole();
        self::assertSame($definition->code, $notification->data['report_code']);
        (new SendNotificationJob($notification))->handle(app(NotificationService::class));
        $processor->process($delivery->id);
        $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'notified']);
        self::assertSame(1, Notification::query()->count());
    }

    private function processor(array $fixture, int $pendingDispatches, bool $recordDelivery, bool $accessDenied = false, bool $failRecording = false): ReportSubscriptionDeliveryProcessor
    {
        [$context, , $delivery, $export, $definition] = $fixture;
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->method('published')->willReturn(new PublishedReportDefinition($definition));
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizationMethod = $context->scope->projectIds === [] ? 'authorizeForOrganization' : 'authorizeExact';
        if ($authorizationMethod === 'authorizeExact') {
            $authorizer->expects(self::never())->method('authorizeForOrganization');
        }
        if ($accessDenied) {
            $authorizer->method($authorizationMethod)->willThrowException(new RuntimeException('authorization_denied'));
        } elseif ($authorizationMethod === 'authorizeExact') {
            $authorizer->method('authorizeExact')->willReturnCallback(
                static function (int $actorId, ReportScope $scope, CurrentReportAuthorizationTarget $target) use ($context): CurrentReportAuthorization {
                    self::assertSame($context->actor->id, $actorId);
                    self::assertSame($context->scope->canonicalIdentity(), $scope->canonicalIdentity());

                    return new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target);
                },
            );
        } else {
            $authorizer->method('authorizeForOrganization')->willReturnCallback(
                static fn (int $actorId, int $organizationId, DateTimeZone $timezone, CurrentReportAuthorizationTarget $target): CurrentReportAuthorization =>
                    new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target),
            );
        }
        $getExport = $this->createMock(GetReportExportAction::class);
        $getExport->method('handle')->willReturn($export);
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::never())->method('handle');
        $exports = $this->createMock(CreateReportExportAction::class);
        $exports->expects(self::never())->method('handle');
        $dispatcher = $this->createMock(ReportSubscriptionDeliveryDispatcher::class);
        $dispatcher->expects(self::exactly($pendingDispatches))->method('dispatch')->with($delivery->id, self::greaterThan(0));
        $events = $this->createMock(ReportSubscriptionEventRecorder::class);
        $record = $events->expects(self::exactly($recordDelivery ? 1 : 0))->method('record')->with('report_subscription_notified', self::anything(), self::anything(), $delivery->id, self::anything(), self::anything());
        if ($failRecording) {
            $record->willThrowException(new RuntimeException('event_recorder_unavailable'));
        }

        return new ReportSubscriptionDeliveryProcessor(
            app(EloquentReportSubscriptionDeliveryStore::class),
            new ReportSubscriptionExecutionContextFactory($authorizer, new ReportExecutionContextFactory, $registry),
            $dispatcher,
            $runs,
            $this->createMock(GetReportRunAction::class),
            $exports,
            $getExport,
            app(PersistedInAppReportSubscriptionNotifier::class),
            $events,
            new ReportSubscriptionPeriodResolver,
        );
    }

    private function fixture(bool $authorized = true, bool $scoped = false): array
    {
        Queue::fake();
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_owner' => false, 'is_active' => true, 'project_access_mode' => 'all']);
        if ($authorized) {
            $role = OrganizationCustomRole::query()->create([
                'organization_id' => $organization->id, 'name' => 'Отчёты', 'slug' => 'subscription_delivery_test',
                'system_permissions' => ['reports.view'], 'module_permissions' => [], 'interface_access' => ['admin'],
                'is_active' => true, 'created_by' => $user->id,
            ]);
            UserRoleAssignment::query()->create([
                'user_id' => $user->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
                'context_id' => AuthorizationContext::getOrganizationContext((int) $organization->id)->id,
                'assigned_by' => $user->id, 'is_active' => true,
            ]);
        }
        $timezone = new DateTimeZone($scoped ? 'Europe/Moscow' : 'UTC');
        $projectIds = $scoped ? [(int) Project::factory()->create(['organization_id' => $organization->id])->id] : [];
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor((int) $user->id, 'active', ['reports.view']))
            ->scope(new ReportScope((int) $organization->id, [(int) $organization->id], $projectIds, [], $timezone))
            ->authorization(new AuthorizationDecisionContext('subscription', (int) $organization->id, [(int) $organization->id], $projectIds, [], $timezone, 'subscription-delivery-test', null))
            ->build();
        $definition = (new ReportDefinitionBuilder)->payload();
        $savedViewId = (string) Str::ulid();
        $now = new DateTimeImmutable;
        DB::table('report_saved_views')->insert([
            'id' => $savedViewId, 'organization_id' => $organization->id, 'owner_id' => $user->id,
            'report_code' => $definition->code, 'contract_version' => $definition->contractVersion, 'name' => 'Отчёт',
            'visibility' => 'private', 'filters_json' => '{}', 'comparison_json' => '{}', 'sort_json' => '{"field":"name","direction":"asc"}',
            'columns_json' => '["name"]', 'status' => 'active', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $input = new ReportSubscriptionExecutionInput(
            $definition->code, new ReportFilterSet($scoped ? ['project_id' => (string) $projectIds[0]] : []), [], 'ru', $savedViewId, 'csv', ['name'],
            new ReportWindowSort('name', ReportSortDirection::ASC), $timezone, ['mode' => 'previous_day'],
            $definition->contractVersion, $definition->definitionHash,
        );
        $subscription = app(EloquentReportSubscriptionStore::class)->create(new ReportSubscription(
            (string) Str::ulid(), (int) $organization->id, (int) $user->id, $savedViewId, $definition->code,
            ReportSubscriptionFrequency::DAILY, null, null, '10:00', $timezone, ['mode' => 'previous_day'], 'csv',
            ReportSubscriptionStatus::ACTIVE, null, 0, $now->modify('+1 day'), $input->canonicalBytes(), $input->digest(),
            $definition->definitionHash, $definition->contractVersion, 1, $now, $now,
        ));
        $store = app(EloquentReportSubscriptionDeliveryStore::class);
        $delivery = $store->createCalendarScheduledLocked($subscription, $now, $input->canonicalBytes(), $input->digest(), 1);
        self::assertNotNull($delivery);
        $export = (new ReportExportBuilder)
            ->createdAt($now->modify('-1 minute'))->updatedAt($now)->readyAt($now)->expiresAt($now->modify('+1 day'))
            ->ready();
        DB::table('report_subscription_deliveries')->where('id', $delivery->id)->update([
            'status' => ReportSubscriptionDeliveryStatus::READY->value, 'run_id' => $export->runId, 'export_id' => $export->id,
        ]);
        [$delivery] = $store->lockWithSubscription($delivery->id);

        return [$context, $subscription, $delivery, $export, $definition];
    }
}
