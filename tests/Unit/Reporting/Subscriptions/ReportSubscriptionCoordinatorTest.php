<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionCoordinator;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionScheduleCalculator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSubscriptionData;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionDeliveryStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionTrigger;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportSubscriptionCoordinatorTest extends TestCase
{
    public function test_create_and_update_freeze_versioned_policy_and_reject_unknown_mode(): void
    {
        $subscription = $this->subscription();
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            new FakeReportSubscriptionDeliveryStore,
            new FakeReportSubscriptionDeliveryDispatcher,
            static fn (callable $callback): mixed => $callback(),
            new FakeReportDefinitionRegistry($this->definition($subscription)),
            new FakeReportSchedulingCapabilityRegistry,
            new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription),
            new ReportSubscriptionScheduleCalculator,
        );
        $context = $this->context($subscription);
        $created = $coordinator->create($context, new CreateReportSubscriptionData(
            $subscription->savedViewId, ReportSubscriptionFrequency::DAILY, null, null,
            '10:00', new DateTimeZone('UTC'), ['mode' => 'fixed_filters'], 'csv',
        ));

        self::assertSame(1, ReportSubscriptionExecutionInput::fromCanonicalBytes($created->executionInputBytes)->periodPolicyVersion);
        self::assertSame($created->savedViewId, ReportSubscriptionExecutionInput::fromCanonicalBytes($created->executionInputBytes)->savedViewReference?->id);
        $coordinator->update($context, $created->id, new UpdateReportSubscriptionData(['format' => 'csv']));
        self::assertSame(1, ReportSubscriptionExecutionInput::fromCanonicalBytes($subscriptions->updates['execution_input_bytes'])->periodPolicyVersion);
        self::assertSame($created->savedViewId, ReportSubscriptionExecutionInput::fromCanonicalBytes($subscriptions->updates['execution_input_bytes'])->savedViewReference?->id);

        $this->expectException(ReportContractException::class);
        $coordinator->update($context, $created->id, new UpdateReportSubscriptionData(['period_policy' => ['mode' => 'whenever']]));
    }

    public function test_legacy_policy_survives_update_without_explicit_policy_change(): void
    {
        $legacy = new ReportSubscriptionExecutionInput(
            'budget_plan_fact', new ReportFilterSet([]), [], 'ru', '01J00000000000000000000002',
            'csv', ['name'], new ReportWindowSort('name', ReportSortDirection::ASC), new DateTimeZone('UTC'),
            ['mode' => 'legacy_mode', 'extra_option' => true], '1.0.0', new Sha256Hash(str_repeat('a', 64)),
        );
        $subscription = $this->subscription(input: $legacy);
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            new FakeReportSubscriptionDeliveryStore,
            new FakeReportSubscriptionDeliveryDispatcher,
            static fn (callable $callback): mixed => $callback(),
            new FakeReportDefinitionRegistry($this->definition($subscription)),
            new FakeReportSchedulingCapabilityRegistry,
            new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription),
            new ReportSubscriptionScheduleCalculator,
        );

        $coordinator->update($this->context($subscription), $subscription->id, new UpdateReportSubscriptionData(['local_time' => '11:00']));
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscriptions->updates['execution_input_bytes']);

        self::assertNull($input->periodPolicyVersion);
        self::assertSame(['extra_option' => true, 'mode' => 'legacy_mode'], $input->periodPolicy);
        self::assertSame('11:00', $subscriptions->updates['local_time']);
    }

    public function test_manual_run_is_idempotent_and_dispatches_only_new_delivery(): void
    {
        $subscription = $this->subscription();
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
            new FakeReportDefinitionRegistry($this->definition($subscription)),
            new FakeReportSchedulingCapabilityRegistry,
            new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription),
            new ReportSubscriptionScheduleCalculator,
        );
        $context = $this->context($subscription);

        $first = $coordinator->runManual(
            $context,
            $subscription->id,
            new IdempotencyKey('manual-key-1'),
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );
        $second = $coordinator->runManual(
            $context,
            $subscription->id,
            new IdempotencyKey('manual-key-1'),
            new DateTimeImmutable('2026-07-26T10:05:00+00:00'),
        );

        self::assertSame($first->id, $second->id);
        self::assertSame([[$first->id, 0]], $dispatcher->calls);
    }

    public function test_manual_run_rejects_reused_key_for_changed_subscription_snapshot(): void
    {
        $subscription = $this->subscription();
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
            new FakeReportDefinitionRegistry($this->definition($subscription)),
            new FakeReportSchedulingCapabilityRegistry,
            new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription),
            new ReportSubscriptionScheduleCalculator,
        );
        $context = $this->context($subscription);
        $idempotencyKey = new IdempotencyKey('manual-key-2');

        $coordinator->runManual(
            $context,
            $subscription->id,
            $idempotencyKey,
            new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
        );

        $subscriptions->subscription = $this->subscription(transitionVersion: 2);

        try {
            $coordinator->runManual(
                $context,
                $subscription->id,
                $idempotencyKey,
                new DateTimeImmutable('2026-07-26T10:05:00+00:00'),
            );
            self::fail('Expected idempotency conflict.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }

        self::assertCount(1, $dispatcher->calls);
    }

    public function test_manual_run_rejects_inactive_subscription(): void
    {
        $subscription = $this->subscription(status: ReportSubscriptionStatus::PAUSED);
        $subscriptions = new FakeReportSubscriptionStore($subscription);
        $deliveries = new FakeReportSubscriptionDeliveryStore;
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $coordinator = new ReportSubscriptionCoordinator(
            $subscriptions,
            $deliveries,
            $dispatcher,
            static fn (callable $callback): mixed => $callback(),
            new FakeReportDefinitionRegistry($this->definition($subscription)),
            new FakeReportSchedulingCapabilityRegistry,
            new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription),
            new ReportSubscriptionScheduleCalculator,
        );
        $context = $this->context($subscription);

        try {
            $coordinator->runManual(
                $context,
                $subscription->id,
                new IdempotencyKey('manual-key-3'),
                new DateTimeImmutable('2026-07-26T10:00:00+00:00'),
            );
            self::fail('Expected inactive subscription rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }

        self::assertSame([], $dispatcher->calls);
    }

    public function test_create_update_preserve_and_replace_frozen_project_with_fresh_management_checks(): void
    {
        $subscription = $this->subscription();
        $checked = [];
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::exactly(3))->method('resolveForProject')->willReturnCallback(
            function (int $actorId, int $organizationId, int $projectId, DateTimeZone $timezone) use ($subscription, &$checked): ReportScope {
                self::assertSame([20, 10, 'UTC'], [$actorId, $organizationId, $timezone->getName()]);
                $checked[] = $projectId;

                return $this->context($subscription, [$projectId])->scope;
            },
        );
        [$coordinator, $store] = $this->scopedCoordinator($subscription, $scopes);
        $created = $coordinator->create($this->context($subscription), new CreateReportSubscriptionData(
            $subscription->savedViewId, ReportSubscriptionFrequency::DAILY, null, null,
            '10:00', new DateTimeZone('UTC'), ['mode' => 'fixed_filters'], 'csv', projectScopeId: 52,
        ));
        self::assertSame(52, ReportSubscriptionExecutionInput::fromCanonicalBytes($created->executionInputBytes)->projectScopeId);
        $coordinator->update($this->context($subscription), $created->id, new UpdateReportSubscriptionData(['local_time' => '11:00']));
        self::assertSame(52, ReportSubscriptionExecutionInput::fromCanonicalBytes($store->updates['execution_input_bytes'])->projectScopeId);
        $coordinator->update($this->context($subscription), $created->id, new UpdateReportSubscriptionData(['project_id' => 53]));
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($store->updates['execution_input_bytes']);
        self::assertSame(53, $input->projectScopeId);
        self::assertSame([], $input->filters->values);
        self::assertSame([52, 52, 53], $checked);
    }

    public function test_explicit_null_clears_project_for_organization_wide_saved_view(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(52));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::never())->method('resolveForProject');
        [$coordinator, $store] = $this->scopedCoordinator($subscription, $scopes);
        $coordinator->update($this->context($subscription), $subscription->id, new UpdateReportSubscriptionData(['project_id' => null, 'format' => 'csv']));
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($store->updates['execution_input_bytes']);
        self::assertNull($input->projectScopeId);
        self::assertNull($input->effectiveProjectId());
    }

    public function test_accepted_project_cannot_be_cleared(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(52, 'accepted_production_progress'));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::never())->method('resolveForProject');
        [$coordinator, $store] = $this->scopedCoordinator($subscription, $scopes);
        try {
            $coordinator->update($this->context($subscription), $subscription->id, new UpdateReportSubscriptionData(['project_id' => null, 'format' => 'csv']));
            self::fail('Expected required project rejection');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame([], $store->updates);
        }
    }

    public function test_scoped_create_without_fresh_authorization_collaborator_fails_closed(): void
    {
        $subscription = $this->subscription();
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::never())->method('resolveForProject');
        [$coordinator] = $this->scopedCoordinator($subscription, $scopes, false);
        try {
            $coordinator->create($this->context($subscription), new CreateReportSubscriptionData(
                $subscription->savedViewId, ReportSubscriptionFrequency::DAILY, null, null,
                '10:00', new DateTimeZone('UTC'), ['mode' => 'fixed_filters'], 'csv', projectScopeId: 52,
            ));
            self::fail('Expected missing project authorization rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        }
    }

    public function test_accepted_subscription_requires_explicit_frozen_project_before_creation(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(null, 'accepted_production_progress'));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::never())->method('resolveForProject');
        [$coordinator] = $this->scopedCoordinator($subscription, $scopes);
        try {
            $coordinator->create($this->context($subscription), new CreateReportSubscriptionData(
                $subscription->savedViewId, ReportSubscriptionFrequency::DAILY, null, null,
                '10:00', new DateTimeZone('UTC'), ['mode' => 'fixed_filters'], 'csv',
            ));
            self::fail('Expected missing accepted project rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
        }
    }

    public function test_revoked_project_prevents_manual_run_before_delivery_dispatch(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(52));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::once())->method('resolveForProject')->willThrowException(new RuntimeException('project_scope_revoked'));
        [$coordinator, , $dispatcher] = $this->scopedCoordinator($subscription, $scopes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project_scope_revoked');
        try {
            $coordinator->runManual($this->context($subscription), $subscription->id, new IdempotencyKey('revoked-project'));
        } finally {
            self::assertSame([], $dispatcher->calls);
        }
    }

    public function test_revoked_project_prevents_resume_but_own_subscription_can_be_paused(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(52));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::once())->method('resolveForProject')->willThrowException(new RuntimeException('project_scope_revoked'));
        [$coordinator, $store] = $this->scopedCoordinator($subscription, $scopes);
        $coordinator->pause($this->context($subscription), $subscription->id);
        self::assertSame('paused', $store->updates['status']);
        $store->subscription = $this->subscription(status: ReportSubscriptionStatus::PAUSED, input: $this->executionInput(52));
        $store->updates = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project_scope_revoked');
        try {
            $coordinator->resume($this->context($subscription), $subscription->id);
        } finally {
            self::assertSame([], $store->updates);
        }
    }

    public function test_created_delivery_keeps_original_project_after_subscription_changes(): void
    {
        $subscription = $this->subscription(input: $this->executionInput(52));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $scopes->expects(self::once())->method('resolveForProject')->with(20, 10, 52, self::anything())
            ->willReturn($this->context($subscription, [52])->scope);
        [$coordinator, $store] = $this->scopedCoordinator($subscription, $scopes);
        $delivery = $coordinator->runManual($this->context($subscription), $subscription->id, new IdempotencyKey('frozen-project'));
        $store->subscription = $this->subscription(transitionVersion: 2, input: $this->executionInput(53));
        self::assertSame(52, $delivery->executionInput()->projectScopeId);
        self::assertSame(53, ReportSubscriptionExecutionInput::fromCanonicalBytes($store->subscription->executionInputBytes)->projectScopeId);
    }

    private function scopedCoordinator(ReportSubscription $subscription, CurrentReportProjectScopeResolver $scopes, bool $withAuthorizer = true): array
    {
        $store = new FakeReportSubscriptionStore($subscription);
        $dispatcher = new FakeReportSubscriptionDeliveryDispatcher;
        $definitions = new FakeReportDefinitionRegistry($this->definition($subscription));
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizer->method('authorizeForOrganization')->willThrowException(new RuntimeException('unexpected_organization_fallback'));
        $authorizer->method('authorizeExact')->willReturnCallback(
            function (int $actorId, ReportScope $scope, CurrentReportAuthorizationTarget $target) use ($subscription): CurrentReportAuthorization {
                self::assertSame($subscription->ownerId, $actorId);
                self::assertSame(ReportOperation::MANAGE, $target->operation);
                $context = $this->context($subscription, $scope->projectIds);

                return new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target);
            },
        );
        $factory = new ReportSubscriptionExecutionContextFactory($authorizer, new ReportExecutionContextFactory, $definitions, $scopes);
        $coordinator = new ReportSubscriptionCoordinator(
            $store, new FakeReportSubscriptionDeliveryStore, $dispatcher,
            static fn (callable $callback): mixed => $callback(), $definitions,
            new FakeReportSchedulingCapabilityRegistry, new FakeReportSavedViewStore($this->savedView($subscription)),
            $this->access($subscription), new ReportSubscriptionScheduleCalculator,
            executionContexts: $withAuthorizer ? $factory : null,
        );

        return [$coordinator, $store, $dispatcher];
    }

    private function executionInput(?int $projectId = null, string $code = 'budget_plan_fact'): ReportSubscriptionExecutionInput
    {
        return new ReportSubscriptionExecutionInput(
            $code, new ReportFilterSet([]), [], 'ru', '01J00000000000000000000002',
            'xlsx', ['name'], new ReportWindowSort('name', ReportSortDirection::ASC), new DateTimeZone('UTC'),
            ['mode' => 'previous_day'], '1.0.0', new Sha256Hash(str_repeat('a', 64)),
            projectScopeId: $projectId,
        );
    }

    private function subscription(
        int $transitionVersion = 1,
        ReportSubscriptionStatus $status = ReportSubscriptionStatus::ACTIVE,
        ?ReportSubscriptionExecutionInput $input = null,
    ): ReportSubscription {
        $input ??= $this->executionInput();
        $bytes = $input->canonicalBytes();
        $now = new DateTimeImmutable('2026-07-26T09:00:00+00:00');

        return new ReportSubscription(
            '01J00000000000000000000001',
            10,
            20,
            '01J00000000000000000000002',
            $input->reportCode,
            ReportSubscriptionFrequency::DAILY,
            null,
            null,
            '10:00',
            new DateTimeZone('UTC'),
            $input->periodPolicy,
            $input->format,
            $status,
            null,
            0,
            new DateTimeImmutable('2026-07-27T10:00:00+00:00'),
            $bytes,
            new Sha256Hash(hash('sha256', $bytes)),
            new Sha256Hash(str_repeat('a', 64)),
            '1.0.0',
            $transitionVersion,
            $now,
            $now,
        );
    }

    private function context(ReportSubscription $subscription, array $projectIds = []): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');

        return (new ReportExecutionContextBuilder)
            ->actor(new ReportActor($subscription->ownerId, 'active', ['reports.export', 'reports.manage', 'reports.view']))
            ->scope(new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope(
                $subscription->organizationId,
                [$subscription->organizationId],
                $projectIds,
                [],
                $timezone,
            ))
            ->authorization(new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http',
                $subscription->organizationId,
                [$subscription->organizationId],
                $projectIds,
                [],
                $timezone,
                'report-subscription-test',
                null,
            ))
            ->build();
    }

    private function savedView(ReportSubscription $subscription): ReportSavedView
    {
        $now = new DateTimeImmutable('2026-07-26T09:00:00+00:00');

        return new ReportSavedView(
            $subscription->savedViewId,
            $subscription->reportCode,
            '1.0.0',
            'Вид отчёта',
            'private',
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([]),
            [],
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort(
                'name',
                \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection::ASC,
            ),
            ['name'],
            'active',
            true,
            $now,
            $now,
        );
    }

    private function definition(ReportSubscription $subscription): PublishedReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->code($subscription->reportCode)
            ->contractVersion($subscription->contractVersion)
            ->supportsSubscriptions(true)
            ->published();
    }

    private function access(ReportSubscription $subscription): ReportAccessService
    {
        $actor = new ReportActor($subscription->ownerId, 'active', ['reports.export', 'reports.manage', 'reports.view']);

        return new ReportAccessService(
            new FakeReportActorLoader($actor),
            new FakeReportSourceAccessResolver,
            new ReportDefinitionVisibilityResolver(
                new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement),
            ),
        );
    }
}

final class FakeReportSubscriptionStore implements ReportSubscriptionStore
{
    public array $updates = [];

    public function __construct(public ReportSubscription $subscription) {}

    public function list(int $organizationId, int $ownerId, ReportSubscriptionWindow $window): ReportSubscriptionPage
    {
        return new ReportSubscriptionPage([], null, $window->limit, false);
    }

    public function getForActor(int $organizationId, int $ownerId, string $id): ReportSubscription
    {
        return $this->subscription;
    }

    public function lock(string $id): ReportSubscription
    {
        return $this->subscription;
    }

    public function create(ReportSubscription $subscription): ReportSubscription
    {
        return $this->subscription = $subscription;
    }

    public function updateLocked(ReportSubscription $subscription, array $changes): ReportSubscription
    {
        $this->updates = $changes;

        return $this->subscription;
    }

    public function softDeleteLocked(ReportSubscription $subscription): void {}

    public function selectDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return [];
    }

    public function advanceNextRunLocked(ReportSubscription $subscription, DateTimeImmutable $nextRun): void {}

    public function disableLocked(ReportSubscription $subscription, string $reason): void {}
}

final class FakeReportSubscriptionDeliveryStore implements ReportSubscriptionDeliveryStore
{
    public function claimStaleDispatches(DateTimeImmutable $now, int $limit, int $staleSeconds): array
    {
        return [];
    }

    /** @var array<string, ReportSubscriptionDelivery> */
    private array $manualDeliveries = [];

    public function lockWithSubscription(string $id): array
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createCalendarScheduledLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?ReportSubscriptionDelivery {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertManualScheduledOnConflictLocked(
        ReportSubscription $subscription,
        DateTimeImmutable $scheduledFor,
        Sha256Hash $triggerKeyHash,
        Sha256Hash $manualRequestHash,
        string $bytes,
        Sha256Hash $hash,
        int $version,
    ): ?string {
        $key = $subscription->id.':'.$triggerKeyHash->value;

        if (isset($this->manualDeliveries[$key])) {
            return null;
        }

        $id = '01J00000000000000000000003';
        $this->manualDeliveries[$key] = new ReportSubscriptionDelivery(
            $id,
            $subscription->organizationId,
            $subscription->ownerId,
            $subscription->id,
            ReportSubscriptionTrigger::MANUAL,
            $triggerKeyHash,
            $manualRequestHash,
            $scheduledFor,
            $bytes,
            $hash,
            $version,
            ReportSubscriptionDeliveryStatus::SCHEDULED,
            0,
            null,
            null,
            null,
            null,
            null,
            $scheduledFor->modify('+1 day'),
            $scheduledFor->modify('+2 days'),
        );

        return $id;
    }

    public function lockManualByScope(string $subscriptionId, Sha256Hash $triggerKeyHash): ?ReportSubscriptionDelivery
    {
        return $this->manualDeliveries[$subscriptionId.':'.$triggerKeyHash->value] ?? null;
    }

    public function beginAttemptLocked(ReportSubscriptionDelivery $delivery): void {}

    public function attachRunLocked(ReportSubscriptionDelivery $delivery, string $runId): void {}

    public function attachExportLocked(ReportSubscriptionDelivery $delivery, string $exportId): void {}

    public function markReadyLocked(ReportSubscriptionDelivery $delivery): void {}

    public function markNotifiedLocked(ReportSubscriptionDelivery $delivery, string $receiptId, Sha256Hash $key): void {}

    public function rescheduleRetryLocked(ReportSubscriptionDelivery $delivery, DateTimeImmutable $retryAt, string $code): void {}

    public function markFailedLocked(ReportSubscriptionDelivery $delivery, string $code): void {}

    public function markExpiredLocked(ReportSubscriptionDelivery $delivery): void {}

    public function expireExecutionsDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return [];
    }

    public function pruneTerminalDueLocked(DateTimeImmutable $now, int $limit): int
    {
        return 0;
    }
}

final class FakeReportSubscriptionDeliveryDispatcher implements ReportSubscriptionDeliveryDispatcher
{
    /** @var list<array{0:string,1:int}> */
    public array $calls = [];

    public function dispatch(string $deliveryId, int $delaySeconds): void
    {
        $this->calls[] = [$deliveryId, $delaySeconds];
    }
}

final class FakeReportDefinitionRegistry implements ReportDefinitionRegistry
{
    public function __construct(private readonly PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $this->definition;
    }

    public function publishedCodes(): array
    {
        return [$this->definition->code];
    }

    public function manifestSha256(): Sha256Hash
    {
        return $this->definition->definitionHash;
    }
}

final class FakeReportSchedulingCapabilityRegistry implements ReportSchedulingCapabilityRegistry
{
    public function published(string $code): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability($code, true, true);
    }
}

final class FakeReportSavedViewStore implements ReportSavedViewStore
{
    public function __construct(private readonly ReportSavedView $view) {}

    public function list(int $organizationId, int $ownerId, \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow $window): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getVisible(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        return $this->view;
    }

    public function lockVisible(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        return $this->getVisible($organizationId, $ownerId, $id);
    }

    public function markNeedsMigrationLocked(int $organizationId, string $id): ReportSavedView
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function create(int $organizationId, int $ownerId, \App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewData $data, string $contractVersion): ReportSavedView
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function updateLocked(int $organizationId, int $ownerId, string $id, \App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSavedViewData $data): ReportSavedView
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setDefaultLocked(int $organizationId, int $ownerId, string $id): ReportSavedView
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function softDeleteLocked(int $organizationId, int $ownerId, string $id): void
    {
        throw new \BadMethodCallException(__METHOD__);
    }
}

final class FakeReportActorLoader implements ReportActorLoader
{
    public function __construct(private readonly ReportActor $actor) {}

    public function loadActive(int $actorId): ReportActor
    {
        return $this->actor;
    }
}

final class FakeReportSourceAccessResolver implements ReportSourceAccessResolver
{
    public function assertAccessible(ReportExecutionContext $context, \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition, ReportSourceRef $source): void {}
}
