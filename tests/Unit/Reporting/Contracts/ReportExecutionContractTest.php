<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportExecutionContractTest extends TestCase
{
    #[Test]
    public function actor_keeps_valid_active_identity(): void
    {
        $actor = new ReportActor(7, 'active', ['reports.view', 'reports.export']);

        self::assertSame(7, $actor->id);
        self::assertSame('active', $actor->status);
        self::assertSame(['reports.export', 'reports.view'], $actor->permissionSlugs);
    }

    #[Test]
    public function actor_rejects_invalid_identity_or_status(): void
    {
        foreach ([[0, 'active'], [1, 'blocked']] as [$id, $status]) {
            try {
                new ReportActor($id, $status, []);
                self::fail('Недопустимый актор был принят.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function authorization_context_exposes_the_authorization_payload(): void
    {
        $context = $this->authorization();

        self::assertSame('correlation-1', $context->correlationId);
        self::assertSame('UTC', $context->timezone->getName());
        self::assertSame([
            'channel' => 'http',
            'organization_id' => 10,
            'project_ids' => [6],
            'resources' => [['kind' => 'task', 'id' => 9, 'project_id' => 6]],
        ], $context->toAuthorizationArray());
    }

    #[Test]
    public function authorization_context_rejects_invalid_channel_organization_or_correlation(): void
    {
        foreach ([['web', 10, 'correlation'], ['http', 0, 'correlation'], ['http', 10, '  ']] as [$channel, $organizationId, $correlationId]) {
            try {
                new AuthorizationDecisionContext($channel, $organizationId, [10], [], [], new DateTimeZone('UTC'), $correlationId, null);
                self::fail('Недопустимый контекст авторизации был принят.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('authorization_context_invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function scope_rejects_duplicate_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportScope(2, [3, 2, 2], [], [], new DateTimeZone('UTC'));
    }

    #[Test]
    public function scope_canonicalizes_identifier_lists_and_identity(): void
    {
        $scope = new ReportScope(2, [3, 2], [8, 4], [
            new ReportScopedResource('task', 9, null),
            new ReportScopedResource('asset', 1, null),
        ], new DateTimeZone('Europe/Moscow'));

        self::assertSame([2, 3], $scope->holdingOrganizationIds);
        self::assertSame(2, $scope->organizationId);
        self::assertSame([4, 8], $scope->projectIds);
        self::assertSame(['asset', 'task'], array_map(static fn (ReportScopedResource $resource): string => $resource->kind, $scope->resources));
        self::assertSame([
            'organization_id' => 2,
            'holding_organization_ids' => [2, 3],
            'project_ids' => [4, 8],
            'resources' => [
                ['kind' => 'asset', 'id' => 1, 'project_id' => null],
                ['kind' => 'task', 'id' => 9, 'project_id' => null],
            ],
            'timezone' => 'Europe/Moscow',
        ], $scope->canonicalIdentity());
    }

    #[Test]
    public function visibility_enforces_dependent_permissions(): void
    {
        $visibility = new ReportVisibility(true, true, true, true, true, true, true);

        self::assertTrue($visibility->canDownload);

        foreach ([[false, false, true, false, false], [false, true, false, false, false], [false, false, false, false, true], [true, false, false, true, false]] as $values) {
            try {
                new ReportVisibility($values[0], $values[1], $values[2], $values[3], $values[4], false, false);
                self::fail('Недопустимая видимость была принята.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function permission_policy_validates_and_canonicalizes_permission_slugs(): void
    {
        $policy = new ReportPermissionPolicy(['reports.view', 'admin.read'], ['reports.export'], [], ['reports.audit']);

        self::assertSame(['admin.read', 'reports.view'], $policy->viewPermissions);
        self::assertSame(['reports.export'], $policy->exportPermissions);
        self::assertSame([], $policy->sensitivePermissions);
        self::assertSame(['reports.audit'], $policy->auditPermissions);
    }

    #[Test]
    public function permission_policy_rejects_invalid_required_or_duplicate_permissions(): void
    {
        foreach ([[[], ['reports.export']], [['reports.view'], []], [['reports.view', 'reports.view'], ['reports.export']], [['reports.view'], ['a']], [['reports.view'], ['reports export']]] as [$view, $export]) {
            try {
                new ReportPermissionPolicy($view, $export, [], []);
                self::fail('Недопустимая политика прав была принята.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function filter_set_canonicalizes_nested_associative_values(): void
    {
        $filters = new ReportFilterSet(['status' => ['z' => true, 'a' => ['b' => 2, 'a' => 1]], 'tags' => ['b', 'a']]);

        self::assertSame(['status' => ['a' => ['a' => 1, 'b' => 2], 'z' => true], 'tags' => ['b', 'a']], $filters->values);
    }

    #[Test]
    public function filter_set_rejects_unsupported_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportFilterSet(['invalid' => NAN]);
    }

    #[Test]
    public function definition_accepts_candidate_and_candidate_wrapper_exposes_the_same_payload(): void
    {
        $definition = $this->definition(ReportPublicationReadiness::CANDIDATE);
        $candidate = new CandidateReportDefinition($definition);

        self::assertSame('sales_overview', $candidate->code);
        self::assertSame($definition->definitionHash, $candidate->definitionHash);
        self::assertSame($definition, $candidate->payload());
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertTrue($definition->supportsSubscriptions);
        self::assertSame(['id' => 'period'], $definition->filters[0]);
    }

    #[Test]
    public function definition_wrappers_enforce_lifecycle_readiness(): void
    {
        $candidate = $this->definition(ReportPublicationReadiness::CANDIDATE);
        $published = $this->definition(ReportPublicationReadiness::PUBLISHED);

        self::assertInstanceOf(PublishedReportDefinition::class, new PublishedReportDefinition($published));

        try {
            new PublishedReportDefinition($candidate);
            self::fail('Кандидат был опубликован без готовности.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('published_definition_readiness_invalid', $exception->getMessage());
        }

        try {
            new CandidateReportDefinition($published);
            self::fail('Опубликованное определение стало кандидатом без готовности.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('candidate_definition_readiness_invalid', $exception->getMessage());
        }
    }

    #[Test]
    public function definition_rejects_invalid_code_versions_collections_and_formats(): void
    {
        foreach ([
            ['ab', 'contract', 'formula', 'source', 'renderer', [['id' => 'period']], [['id' => 'total']], [['id' => 'period']], ['csv']],
            ['sales_overview', ' ', 'formula', 'source', 'renderer', [['id' => 'period']], [['id' => 'total']], [['id' => 'period']], ['csv']],
            ['sales_overview', 'contract', 'formula', 'source', 'renderer', [['id' => 'period'], ['id' => 'period']], [['id' => 'total']], [['id' => 'period']], ['csv']],
            ['sales_overview', 'contract', 'formula', 'source', 'renderer', [['id' => 'period']], [['id' => 'total']], [['id' => 'period']], ['json']],
        ] as $input) {
            try {
                new ReportDefinition($input[0], $this->hash(), $input[1], $input[2], $input[3], $input[4], $input[5], $input[6], $input[7], $input[8], $this->policy(), \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification::STANDARD, [], [], false, false, false), ReportPublicationReadiness::CANDIDATE, false, 'reports', \App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode::REPORTING_WORKSPACE);
                self::fail('Недопустимое определение отчёта было принято.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function execution_context_keeps_matching_scope_and_authorization_context(): void
    {
        $context = new ReportExecutionContext($this->actor(), $this->scope(), new ReportVisibility(true, true, true, true, false, false, false), $this->authorization());

        self::assertSame('correlation-1', $context->correlationId());
        self::assertSame(10, $context->scope->organizationId);
    }

    #[Test]
    public function execution_context_rejects_scope_mismatch(): void
    {
        $authorization = new AuthorizationDecisionContext('http', 11, [11], [6], [new ReportScopedResource('task', 9, 6)], new DateTimeZone('UTC'), 'correlation-1', null);

        $this->expectExceptionObject(new InvalidArgumentException('execution_context_scope_mismatch'));
        new ReportExecutionContext($this->actor(), $this->scope(), new ReportVisibility(true, false, false, false, false, false, false), $authorization);
    }

    #[Test]
    public function query_hash_is_independent_from_client_associative_field_order(): void
    {
        $definition = $this->definition(ReportPublicationReadiness::PUBLISHED);
        $scope = $this->scope();
        $left = new ReportQuery($definition, $scope, new ReportFilterSet(['period' => ['to' => '2026-01-31', 'from' => '2026-01-01']]), ['right' => 2, 'left' => 1], new DateTimeImmutable('2026-02-01T00:00:00+00:00'), 'ru');
        $right = new ReportQuery($definition, $scope, new ReportFilterSet(['period' => ['from' => '2026-01-01', 'to' => '2026-01-31']]), ['left' => 1, 'right' => 2], new DateTimeImmutable('2026-02-01T00:00:00+00:00'), 'ru');

        self::assertSame($left->canonicalJson, $right->canonicalJson);
        self::assertSame($left->queryHash->value, $right->queryHash->value);
        self::assertStringContainsString('"definition_hash"', $left->canonicalJson);
        self::assertSame(64, strlen($left->queryHash->value));
    }

    #[Test]
    public function progress_is_monotonic(): void
    {
        $progress = new ReportProgress(20);

        self::assertSame(20, $progress->percent());

        try {
            $progress->advance(19);
            self::fail('Прогресс уменьшился.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertFalse($progress->advance(20));
        self::assertTrue($progress->advance(21));
        self::assertSame(21, $progress->percent());
    }

    #[Test]
    public function progress_notifies_observer_immediately_after_each_advance(): void
    {
        $observed = [];
        $progress = new ReportProgress(0, static function (ReportProgress $current) use (&$observed): void {
            $observed[] = $current->percent();
        });

        $progress->advance(10);
        $progress->advance(25);

        self::assertSame([10, 25], $observed);
    }

    #[Test]
    public function progress_maps_completed_work_to_a_bounded_stage(): void
    {
        $progress = new ReportProgress(5);

        self::assertTrue($progress->advanceProportion(0, 4, 10, 90));
        self::assertSame(10, $progress->percent());
        self::assertTrue($progress->advanceProportion(1, 4, 10, 90));
        self::assertSame(30, $progress->percent());
        self::assertTrue($progress->advanceProportion(4, 4, 10, 90));
        self::assertSame(90, $progress->percent());
    }

    #[Test]
    public function window_sort_validates_its_field(): void
    {
        $sort = new ReportWindowSort('created_at', ReportSortDirection::DESC);

        self::assertSame('created_at', $sort->field);
        self::assertSame(ReportSortDirection::DESC, $sort->direction);

        $this->expectException(InvalidArgumentException::class);
        new ReportWindowSort('raw sql', ReportSortDirection::ASC);
    }

    #[Test]
    public function rows_window_limits_cursor_and_sort(): void
    {
        $sort = new ReportWindowSort('created_at', ReportSortDirection::ASC);
        $window = new ReportRowsWindow('signed.transport.token', 100, $sort);

        self::assertSame('signed.transport.token', $window->cursor);
        self::assertSame(100, $window->limit);

        foreach ([0, 101] as $limit) {
            try {
                new ReportRowsWindow(null, $limit, $sort);
                self::fail('Недопустимый лимит окна был принят.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function actor(): ReportActor
    {
        return new ReportActor(7, 'active', ['reports.view']);
    }

    private function authorization(): AuthorizationDecisionContext
    {
        return new AuthorizationDecisionContext('http', 10, [10], [6], [new ReportScopedResource('task', 9, 6)], new DateTimeZone('UTC'), 'correlation-1', null);
    }

    private function scope(): ReportScope
    {
        return new ReportScope(10, [10], [6], [new ReportScopedResource('task', 9, 6)], new DateTimeZone('UTC'));
    }

    private function policy(): ReportPermissionPolicy
    {
        return new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []);
    }

    private function definition(ReportPublicationReadiness $readiness): ReportDefinition
    {
        return new ReportDefinition(
            'sales_overview',
            $this->hash(),
            'contract-v1',
            'formula-v1',
            'source-v1',
            'renderer-v1',
            [['id' => 'period']],
            [['id' => 'total']],
            [['id' => 'period']],
            ['csv', 'xlsx'],
            $this->policy(),
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification::STANDARD, [], [], false, false, false),
            $readiness,
            true,
            'reports',
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode::REPORTING_WORKSPACE,
        );
    }

    private function hash(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
