<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterReferenceResolver;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionDeliveryProcessor;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\InAppReportSubscriptionNotifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryDispatcher;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionEventRecorder;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionDeliveryStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSubscriptionStore;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Reporting\StableReportingSourceView;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;
use Tests\TestCase;

#[Group('postgres-contract')]
final class ReportSubscriptionRunCreationTest extends TestCase
{
    #[DataProvider('creationStates')]
    public function test_run_creation_uses_committed_claim_and_frozen_scope(string $status, int $attempt, bool $holding = false): void
    {
        $fixture = $this->fixture($status, $attempt, $holding);
        [$context, $delivery, $definition, $input] = $fixture;
        DB::table('report_subscriptions')->where('id', $delivery->subscriptionId)->update(['report_code' => 'new_report', 'timezone' => 'UTC']);
        $run = (new ReportRunBuilder)->queued();
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::once())->method('handle')->willReturnCallback(
            function (ReportExecutionContext $actual, CreateReportRunData $data, IdempotencyKey $key) use ($context, $delivery, $definition, $input, $run): ReportRun {
                self::assertSame(0, DB::transactionLevel());
                self::assertSame('repeatable read', (new StableReportingSourceView)->capture(static fn (): string => DB::selectOne('SHOW transaction_isolation')->transaction_isolation));
                $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => null]);
                self::assertSame($context->scope->canonicalIdentity(), $actual->scope->canonicalIdentity());
                self::assertSame($input->reportCode, $data->reportCode);
                self::assertSame($input->savedViewReference->hash->value, $data->savedViewReference?->hash->value);
                self::assertSame($delivery->scheduledFor->format(DATE_ATOM), $data->asOf->format(DATE_ATOM));
                self::assertSame($delivery->runIdempotencyKey()->hash, $key->hash);
                self::assertSame($input->filters->values['project_id'], $data->filters->values['project_id']);
                self::assertSame($data->filters->values, (new ReportFilterNormalizer($this->createMock(ReportFilterReferenceResolver::class)))->normalize($actual, $definition, $data->filters->values)->values);

                return $run;
            },
        );
        DB::commit();
        try {
            $this->processor($fixture, $runs, 1)->process($delivery->id);
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => $run->id]);
        } finally {
            DB::beginTransaction();
        }
    }

    public static function creationStates(): array
    {
        return [['scheduled', 0], ['building_run', 1], ['scheduled', 0, true]];
    }

    public function test_interrupted_attach_recovers_same_run_without_new_attempt(): void
    {
        $fixture = $this->fixture();
        [, $delivery] = $fixture;
        $run = (new ReportRunBuilder)->queued();
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::exactly(2))->method('handle')->with(self::anything(), self::anything(), self::callback(static fn (IdempotencyKey $key): bool => $key->hash === $delivery->runIdempotencyKey()->hash))->willReturn($run);
        $realStore = app(EloquentReportSubscriptionDeliveryStore::class);
        $failingStore = $this->createMock(ReportSubscriptionDeliveryStore::class);
        $failingStore->method('lockWithSubscription')->willReturnCallback($realStore->lockWithSubscription(...));
        $failingStore->method('beginAttemptLocked')->willReturnCallback($realStore->beginAttemptLocked(...));
        $failingStore->expects(self::once())->method('attachRunLocked')->willThrowException(new RuntimeException('attach_interrupted'));
        DB::commit();
        try {
            try {
                $this->processor($fixture, $runs, 0, $failingStore)->process($delivery->id);
                self::fail('The attachment interruption must propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame('attach_interrupted', $exception->getMessage());
            }
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => null]);
            $this->processor($fixture, $runs, 1)->process($delivery->id);
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => $run->id]);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_expired_delivery_cannot_attach_or_dispatch_a_created_run(): void
    {
        $fixture = $this->fixture();
        [, $delivery] = $fixture;
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::once())->method('handle')->willReturnCallback(function () use ($delivery): ReportRun {
            DB::table('report_subscription_deliveries')->where('id', $delivery->id)->update(['execution_expires_at' => (new DateTimeImmutable)->modify('-1 minute')]);
            app(EloquentReportSubscriptionDeliveryStore::class)->expireExecutionsDueLocked(new DateTimeImmutable, 100);

            return (new ReportRunBuilder)->queued();
        });
        DB::commit();
        try {
            $this->processor($fixture, $runs, 0)->process($delivery->id);
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'expired', 'attempt' => 1, 'run_id' => null]);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_concurrent_attachment_never_overwrites_another_run(): void
    {
        $fixture = $this->fixture();
        [, $delivery] = $fixture;
        $otherId = (string) Str::ulid();
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::once())->method('handle')->willReturnCallback(function () use ($delivery, $otherId): ReportRun {
            DB::transaction(function () use ($delivery, $otherId): void {
                $store = app(EloquentReportSubscriptionDeliveryStore::class);
                [$current] = $store->lockWithSubscription($delivery->id);
                $store->attachRunLocked($current, $otherId);
                $store->attachRunLocked($current, $otherId);
                try {
                    $store->attachRunLocked($current, (new ReportRunBuilder)->queued()->id);
                    self::fail('The store must not overwrite a different run.');
                } catch (LogicException $exception) {
                    self::assertSame('report_subscription_delivery_concurrent_change', $exception->getMessage());
                }
            });

            return (new ReportRunBuilder)->queued();
        });
        DB::commit();
        try {
            try {
                $this->processor($fixture, $runs, 0)->process($delivery->id);
                self::fail('A different attached run must be preserved.');
            } catch (LogicException $exception) {
                self::assertSame('report_subscription_delivery_concurrent_change', $exception->getMessage());
            }
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'run_id' => $otherId]);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_event_failure_rolls_back_run_attachment_and_recovers_with_same_key(): void
    {
        $fixture = $this->fixture();
        [, $delivery] = $fixture;
        $run = (new ReportRunBuilder)->queued();
        $runs = $this->createMock(CreateReportRunAction::class);
        $runs->expects(self::exactly(2))->method('handle')->with(self::anything(), self::anything(), self::callback(static fn (IdempotencyKey $key): bool => $key->hash === $delivery->runIdempotencyKey()->hash))->willReturn($run);
        DB::commit();
        try {
            try {
                $this->processor($fixture, $runs, 0, failRecording: true)->process($delivery->id);
                self::fail('The event failure must roll back the attachment.');
            } catch (RuntimeException $exception) {
                self::assertSame('event_recorder_unavailable', $exception->getMessage());
            }
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => null]);
            $this->processor($fixture, $runs, 1)->process($delivery->id);
            $this->assertDatabaseHas('report_subscription_deliveries', ['id' => $delivery->id, 'status' => 'building_run', 'attempt' => 1, 'run_id' => $run->id]);
        } finally {
            DB::beginTransaction();
        }
    }

    private function processor(array $fixture, CreateReportRunAction $runs, int $dispatchCount, ?ReportSubscriptionDeliveryStore $store = null, bool $failRecording = false): ReportSubscriptionDeliveryProcessor
    {
        [$context, $delivery, $definition] = $fixture;
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->method('published')->with($definition->code)->willReturn(new PublishedReportDefinition($definition));
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $projectScopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $projectScopes->expects(self::once())->method('resolveForProject')
            ->with($context->actor->id, $context->scope->organizationId, $context->scope->projectIds[0], $context->scope->timezone)
            ->willReturn($context->scope);
        $authorizer->expects(self::once())->method('authorizeExact')->willReturnCallback(
            static function (int $actorId, ReportScope $scope, CurrentReportAuthorizationTarget $target) use ($context): CurrentReportAuthorization {
                self::assertSame($context->actor->id, $actorId);
                self::assertSame($context->scope->canonicalIdentity(), $scope->canonicalIdentity());
                self::assertSame(ReportOperation::RUN, $target->operation);

                return new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target);
            },
        );
        $dispatcher = $this->createMock(ReportSubscriptionDeliveryDispatcher::class);
        $dispatcher->expects(self::exactly($dispatchCount))->method('dispatch')->with($delivery->id, 0)->willReturnCallback(static function (): void {
            self::assertSame(0, DB::transactionLevel());
        });
        $events = $this->createMock(ReportSubscriptionEventRecorder::class);
        $record = $events->expects(self::exactly($dispatchCount + (int) $failRecording))->method('record')->with('report_subscription_run_requested', self::anything(), self::anything(), $delivery->id, self::anything(), self::anything())->willReturnCallback(static function (): void {
            self::assertSame(1, DB::transactionLevel());
        });
        if ($failRecording) {
            $record->willThrowException(new RuntimeException('event_recorder_unavailable'));
        }

        return new ReportSubscriptionDeliveryProcessor(
            $store ?? app(EloquentReportSubscriptionDeliveryStore::class),
            new ReportSubscriptionExecutionContextFactory($authorizer, new ReportExecutionContextFactory, $registry, $projectScopes),
            $dispatcher, $runs, $this->createMock(GetReportRunAction::class), $this->createMock(CreateReportExportAction::class),
            $this->createMock(GetReportExportAction::class), $this->createMock(InAppReportSubscriptionNotifier::class),
            $events, new ReportSubscriptionPeriodResolver,
        );
    }

    private function fixture(string $status = 'scheduled', int $attempt = 0, bool $holding = false): array
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $projectOrganization = $organization;
        if ($holding) {
            $organization->update(['is_holding' => true]);
            $projectOrganization = Organization::factory()->verified()->create(['parent_organization_id' => $organization->id]);
        }
        $holdingIds = array_values(array_unique([(int) $organization->id, (int) $projectOrganization->id]));
        $project = Project::factory()->create(['organization_id' => $projectOrganization->id]);
        $timezone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope((int) $organization->id, $holdingIds, [(int) $project->id], [], $timezone);
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor((int) $user->id, 'active', ['reports.view', 'reports.run']))->scope($scope)
            ->authorization(new AuthorizationDecisionContext('subscription', (int) $organization->id, $holdingIds, [(int) $project->id], [], $timezone, 'subscription-creation-test', null))->build();
        $definition = (new ReportDefinitionBuilder)->filters([
            ['id' => 'project_id', 'type' => 'integer', 'operators' => ['eq']],
            ['id' => 'period_from', 'type' => 'date', 'operators' => ['eq']],
            ['id' => 'period_to', 'type' => 'date', 'operators' => ['eq']],
        ])->payload();
        $viewId = (string) Str::ulid();
        $now = new DateTimeImmutable;
        $filters = (new ReportFilterNormalizer($this->createMock(ReportFilterReferenceResolver::class)))->normalize($context, $definition, [
            'project_id' => ['operator' => 'eq', 'value' => (string) $project->id],
            'period_from' => ['operator' => 'eq', 'value' => '2026-10-01'],
            'period_to' => ['operator' => 'eq', 'value' => '2026-10-07'],
        ]);
        DB::table('report_saved_views')->insert([
            'id' => $viewId, 'organization_id' => $organization->id, 'owner_id' => $user->id,
            'report_code' => $definition->code, 'contract_version' => $definition->contractVersion, 'name' => 'Own report',
            'visibility' => 'private', 'filters_json' => json_encode($filters->values, JSON_THROW_ON_ERROR), 'comparison_json' => '{}',
            'sort_json' => '{"field":"name","direction":"asc"}', 'columns_json' => '["name"]', 'status' => 'active',
            'is_default' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $view = app(ReportSavedViewStore::class)->getVisible((int) $organization->id, (int) $user->id, $viewId);
        $input = new ReportSubscriptionExecutionInput(
            $definition->code, $filters, [], 'ru', $viewId, 'csv', ['name'],
            new ReportWindowSort('name', ReportSortDirection::ASC), $timezone, ['mode' => 'previous_week'],
            $definition->contractVersion, $definition->definitionHash, 1, ReportSavedViewRef::fromView($view),
        );
        $subscription = app(EloquentReportSubscriptionStore::class)->create(new ReportSubscription(
            (string) Str::ulid(), (int) $organization->id, (int) $user->id, $viewId, $definition->code,
            ReportSubscriptionFrequency::WEEKLY, 5, null, '10:00', $timezone, ['mode' => 'previous_week'], 'csv',
            ReportSubscriptionStatus::ACTIVE, null, 0, $now->modify('+1 week'), $input->canonicalBytes(), $input->digest(),
            $definition->definitionHash, $definition->contractVersion, 1, $now, $now,
        ));
        $store = app(EloquentReportSubscriptionDeliveryStore::class);
        $delivery = $store->createCalendarScheduledLocked($subscription, $now, $input->canonicalBytes(), $input->digest(), 1);
        self::assertNotNull($delivery);
        DB::table('report_subscription_deliveries')->where('id', $delivery->id)->update(['status' => $status, 'attempt' => $attempt]);
        [$delivery] = $store->lockWithSubscription($delivery->id);

        return [$context, $delivery, $definition, $input];
    }
}
