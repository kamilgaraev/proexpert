<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterNormalizer;
use App\BusinessModules\Core\Reporting\Application\Input\ReportFilterReferenceResolver;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportSubscriptionExecutionContextFactoryTest extends TestCase
{
    #[DataProvider('projectFilters')]
    public function test_reads_normalized_project_value_and_reauthorizes_exact_scope(mixed $filter): void
    {
        [$factory, $subscription, $context, $scopes, $authorizer] = $this->fixture($filter);
        $scopes->expects(self::once())->method('resolveForProject')->with(31, 7, 52, $context->scope->timezone)->willReturn($context->scope);
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $authorizer->expects(self::once())->method('authorizeExact')->with(31, $context->scope, self::anything())->willReturnCallback(
            static fn (int $actorId, ReportScope $scope, CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target),
        );

        self::assertSame($context->scope->canonicalIdentity(), $factory->forSubscription($subscription, ReportOperation::RUN)->scope->canonicalIdentity());
    }

    public static function projectFilters(): array
    {
        return [['normalized'], [52], ['52']];
    }

    #[DataProvider('invalidProjectFilters')]
    public function test_rejects_ambiguous_project_filters_before_scope_or_acl(mixed $filter): void
    {
        [$factory, $subscription, , $scopes, $authorizer] = $this->fixture($filter);
        $scopes->expects(self::never())->method('resolveForProject');
        $authorizer->expects(self::never())->method('authorizeExact');
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('report_subscription_project_scope_invalid');
        $factory->forSubscription($subscription, ReportOperation::RUN);
    }

    public static function invalidProjectFilters(): array
    {
        return [
            [['operator' => 'neq', 'value' => 52]],
            [['operator' => 'in', 'value' => [52, 53]]],
            [['operator' => 'eq', 'value' => 0]],
            [['operator' => 'eq', 'value' => true]],
            [['operator' => 'eq', 'value' => 52.5]],
            [['operator' => 'eq', 'value' => [52]]],
            [['operator' => 'eq', 'value' => 52, 'other' => 1]],
            [true], [0], [-1],
        ];
    }

    public function test_accepted_report_without_project_scope_cannot_fall_back_to_the_whole_organization(): void
    {
        [$factory, $subscription, , $scopes, $authorizer] = $this->fixture(null, 'accepted_production_progress');
        $scopes->expects(self::never())->method('resolveForProject');
        $authorizer->expects(self::never())->method('authorizeExact');
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('report_subscription_project_scope_invalid');
        $factory->forSubscription($subscription, ReportOperation::RUN);
    }

    #[DataProvider('projectOperations')]
    public function test_accepted_report_reauthorizes_frozen_project_without_a_project_filter(ReportOperation $operation): void
    {
        [$factory, $subscription, $context, $scopes, $authorizer] = $this->fixture(null, 'accepted_production_progress', 52);
        $scopes->expects(self::once())->method('resolveForProject')->with(31, 7, 52, $context->scope->timezone)->willReturn($context->scope);
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $authorizer->expects(self::once())->method('authorizeExact')->with(31, $context->scope, self::anything())->willReturnCallback(
            static function (int $actorId, ReportScope $scope, CurrentReportAuthorizationTarget $target) use ($context, $operation): CurrentReportAuthorization {
                self::assertSame($operation, $target->operation);

                return new CurrentReportAuthorization($context->actor, $context->authorization, $context->visibility, $target);
            },
        );
        self::assertSame($context->scope->canonicalIdentity(), $factory->forSubscription($subscription, $operation)->scope->canonicalIdentity());
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        self::assertSame(52, $input->projectScopeId);
        self::assertSame([], $input->filters->values);
        self::assertSame($subscription->executionInputBytes, $input->canonicalBytes());
        self::assertSame($subscription->executionInputHash->value, $input->digest()->value);
    }

    public static function projectOperations(): array
    {
        return [[ReportOperation::RUN], [ReportOperation::MANAGE], [ReportOperation::VIEW]];
    }

    public function test_accepted_report_does_not_infer_project_scope_from_legacy_filter(): void
    {
        [$factory, $subscription, , $scopes, $authorizer] = $this->fixture(52, 'accepted_production_progress');
        $scopes->expects(self::never())->method('resolveForProject');
        $authorizer->expects(self::never())->method('authorizeExact');
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('report_subscription_project_scope_invalid');
        $factory->forSubscription($subscription, ReportOperation::RUN);
    }

    public function test_frozen_project_revocation_is_not_replaced_with_organization_scope(): void
    {
        [$factory, $subscription, , $scopes, $authorizer] = $this->fixture(null, 'accepted_production_progress', 52);
        $scopes->expects(self::once())->method('resolveForProject')->willThrowException(new RuntimeException('current_project_scope_denied'));
        $authorizer->expects(self::never())->method('authorizeExact');
        $authorizer->expects(self::never())->method('authorizeForOrganization');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current_project_scope_denied');
        $factory->forSubscription($subscription, ReportOperation::RUN);
    }

    public function test_legacy_execution_input_keeps_scope_key_absent_and_bytes_unchanged(): void
    {
        [, $subscription] = $this->fixture(null);
        $input = ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        self::assertArrayNotHasKey('project_scope_id', json_decode($subscription->executionInputBytes, true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($input->projectScopeId);
        self::assertSame($subscription->executionInputBytes, $input->canonicalBytes());
        self::assertSame($subscription->executionInputHash->value, $input->digest()->value);
    }

    #[DataProvider('invalidFrozenProjects')]
    public function test_execution_input_rejects_noncanonical_frozen_project(mixed $projectId): void
    {
        [, $subscription] = $this->fixture(null);
        $input = json_decode($subscription->executionInputBytes, true, 512, JSON_THROW_ON_ERROR);
        $input['project_scope_id'] = $projectId;
        $this->expectException(InvalidArgumentException::class);
        ReportSubscriptionExecutionInput::fromCanonicalBytes(CanonicalJson::encode($input));
    }

    public static function invalidFrozenProjects(): array
    {
        return [[null], ['52'], [true], [0], [-1], [52.5], [[]]];
    }

    private function fixture(mixed $filter, string $code = 'report', ?int $projectScopeId = null): array
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor(31, 'active', ['reports.view', 'reports.run', 'reports.manage']))
            ->scope(new ReportScope(7, [7, 8], [52], [], $timezone))
            ->authorization(new AuthorizationDecisionContext('subscription', 7, [7, 8], [52], [], $timezone, 'frozen-project-test', null))->build();
        $definition = (new ReportDefinitionBuilder)->code($code)->filters($code === 'accepted_production_progress'
            ? [['id' => 'period_from', 'type' => 'date', 'operators' => ['eq']]]
            : [['id' => 'project_id', 'type' => 'integer', 'operators' => ['eq']]])->payload();
        $filters = $filter === 'normalized'
            ? (new ReportFilterNormalizer($this->createMock(ReportFilterReferenceResolver::class)))->normalize($context, $definition, ['project_id' => ['operator' => 'eq', 'value' => '52']])
            : new ReportFilterSet($filter === null ? [] : ['project_id' => $filter]);
        $input = new ReportSubscriptionExecutionInput(
            $definition->code, $filters, [], 'ru', '01J00000000000000000000002', 'csv', ['name'],
            new ReportWindowSort('name', ReportSortDirection::ASC), $timezone, ['mode' => 'fixed_filters'], $definition->contractVersion, $definition->definitionHash, 1,
            projectScopeId: $projectScopeId,
        );
        $now = new DateTimeImmutable('2026-10-16T09:00:00Z');
        $subscription = new ReportSubscription(
            '01J00000000000000000000001', 7, 31, $input->savedViewId, $definition->code,
            ReportSubscriptionFrequency::DAILY, null, null, '10:00', $timezone, ['mode' => 'fixed_filters'], 'csv',
            ReportSubscriptionStatus::ACTIVE, null, 0, $now->modify('+1 day'), $input->canonicalBytes(), $input->digest(),
            $definition->definitionHash, $definition->contractVersion, 1, $now, $now,
        );
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $registry->method('published')->with($definition->code)->willReturn(new PublishedReportDefinition($definition));
        $scopes = $this->createMock(CurrentReportProjectScopeResolver::class);
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);

        return [new ReportSubscriptionExecutionContextFactory($authorizer, new ReportExecutionContextFactory, $registry, $scopes), $subscription, $context, $scopes, $authorizer];
    }
}
