<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOwnerSourcePolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ProjectPortfolioHealthOwnerSourcePolicyTest extends TestCase
{
    #[Test]
    public function it_rejects_stale_incomplete_or_foreign_owner_snapshots(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = $this->snapshotRequest();
        $source = $this->snapshotSource();

        self::assertTrue($policy->accepts($request, $source));
        self::assertFalse($policy->accepts($request, [...$source, 'stale_at' => '2026-08-04T00:00:00+00:00']));
        self::assertFalse($policy->accepts($request, [...$source, 'coverage_denominator' => 2]));
        self::assertFalse($policy->accepts($request, [...$source, 'organization_id' => 39]));
        self::assertFalse($policy->accepts($request, [...$source, 'parent_report_scope' => 'plan_fact']));
        self::assertFalse($policy->accepts($request, [...$source, 'parent_scope_hash_valid' => false]));
    }

    #[Test]
    public function it_rejects_a_semantically_foreign_owner_query_and_hidden_narrowing(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            ...$this->snapshotRequest(),
            'expected_definition_hash' => str_repeat('2', 64),
            'expected_query_hash' => str_repeat('3', 64),
        ];
        $source = $this->snapshotSource();

        self::assertTrue($policy->accepts($request, $source));
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'definition_hash' => str_repeat('6', 64),
        ]));
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'query_hash' => str_repeat('7', 64),
        ]));
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'filters' => [...$source['filters'], 'budget_article_id' => 99],
        ]));
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'budget_version_id' => 12,
        ]));
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'closure_hash' => str_repeat('5', 64),
        ]));

        $cohort = $policy->cohortHash($request, $source);
        $foreignCohort = $policy->cohortHash($request, [
            ...$source,
            'filters' => [...$source['filters'], 'scenario_uuid' => 'scenario-v2'],
        ]);
        self::assertNotNull($cohort);
        self::assertNotNull($foreignCohort);
        self::assertNotSame($cohort, $foreignCohort);
    }

    #[Test]
    public function it_reconstructs_the_route_injected_project_query_hash(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            ...$this->snapshotRequest(),
            'scope_project_ids' => [10],
            'project_ids' => [10],
        ];
        $source = [
            ...$this->snapshotSource(),
            'project_id' => 10,
            'row_project_ids' => [10],
        ];
        $filters = $policy->canonicalQueryFilters($request, $source);

        self::assertNotNull($filters);
        self::assertSame(38, $filters['organization_id']);
        self::assertSame(10, $filters['project_id']);

        $definition = (new ReportDefinitionBuilder)
            ->code('project_margin')
            ->formulaVersion('margin-v1')
            ->sourceSchemaVersion('project_margin_source_v1')
            ->columns([['id' => 'row_key']])
            ->sorts([['id' => 'row_key']])
            ->payload();
        $scope = new ReportScope(38, [38], [10], [], new DateTimeZone('UTC'));
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet($filters),
            [],
            new DateTimeImmutable('2026-08-04T00:00:00+00:00'),
            'ru',
        );
        $filtersWithoutProject = $filters;
        unset($filtersWithoutProject['project_id']);
        $queryWithoutProject = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet($filtersWithoutProject),
            [],
            new DateTimeImmutable('2026-08-04T00:00:00+00:00'),
            'ru',
        );

        self::assertNotSame($queryWithoutProject->queryHash->value, $query->queryHash->value);
        self::assertTrue($policy->accepts([
            ...$request,
            'expected_definition_hash' => $definition->definitionHash->value,
            'expected_query_hash' => $query->queryHash->value,
        ], [
            ...$source,
            'definition_hash' => $definition->definitionHash->value,
            'query_hash' => $query->queryHash->value,
        ]));
    }

    #[Test]
    public function it_accepts_a_selected_project_subset_and_multi_currency_coverage(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            ...$this->snapshotRequest(),
            'scope_project_ids' => [10, 20],
            'project_ids' => [10],
            'currencies' => ['RUB', 'USD'],
        ];
        $source = [
            ...$this->snapshotSource(),
            'project_id' => null,
            'currency' => null,
            'row_project_ids' => [10],
            'row_currencies' => ['RUB', 'USD'],
        ];

        self::assertTrue($policy->accepts($request, $source));
        $filters = $policy->canonicalQueryFilters($request, $source);
        self::assertNotNull($filters);
        self::assertSame(10, $filters['project_id']);
        self::assertArrayNotHasKey('currency', $filters);

        self::assertFalse($policy->accepts([
            ...$request,
            'scope_project_ids' => [10, 20, 30],
            'project_ids' => [10, 20],
        ], [
            ...$source,
            'row_project_ids' => [10, 20],
        ]));
    }

    #[Test]
    public function unfiltered_owner_sources_cover_multiple_selected_dimensions(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            ...$this->snapshotRequest(),
            'responsibility_center_ids' => [30, 31],
            'responsibility_center_uuids' => [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
            ],
            'counterparty_ids' => [40, 41],
        ];
        $source = $this->snapshotSource();
        $source['filters'] = [
            'close_id' => '01K1CLOSE00000000000000000',
            'scenario_uuid' => 'scenario-v1',
            'budget_version_uuid' => 'budget-v1',
            'group_by' => ['project', 'currency'],
        ];

        self::assertTrue($policy->accepts($request, $source));
        $filters = $policy->canonicalQueryFilters($request, $source);
        self::assertNotNull($filters);
        self::assertArrayNotHasKey('responsibility_center_id', $filters);
        self::assertArrayNotHasKey('counterparty_id', $filters);
        self::assertFalse($policy->accepts($request, [
            ...$source,
            'filters' => [...$source['filters'], 'responsibility_center_id' => 30],
        ]));
    }

    #[Test]
    public function it_accepts_only_an_exact_owner_scope(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = $this->request();
        $source = $this->source();

        self::assertTrue($policy->matches($request, $source));
        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'currencies' => ['USD']],
            'currency' => 'USD',
            'row_currencies' => ['USD'],
        ]));
        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'responsibility_center_ids' => [99]],
        ]));
        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'counterparty_ids' => [77]],
        ]));
        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'project_ids' => [10]],
            'row_project_ids' => [10],
        ]));
    }

    #[Test]
    public function it_rejects_hidden_source_narrowing_when_the_parent_filter_is_omitted(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            'scope_project_ids' => [10, 20],
            'project_ids' => [10, 20],
            'currencies' => [],
            'responsibility_center_ids' => [],
            'counterparty_ids' => [],
        ];
        $source = [
            'project_id' => null,
            'currency' => 'RUB',
            'filters' => [],
            'row_project_ids' => [10, 20],
            'row_currencies' => ['RUB'],
        ];

        self::assertFalse($policy->matches($request, $source));
        self::assertTrue($policy->matches($request, [...$source, 'currency' => null]));
    }

    #[Test]
    public function it_requires_an_explicit_parent_project_scope_for_a_selected_subset(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = [
            'scope_project_ids' => [10, 20],
            'project_ids' => [10],
            'currencies' => [],
            'responsibility_center_ids' => [],
            'counterparty_ids' => [],
        ];
        $source = [
            'project_id' => null,
            'currency' => null,
            'filters' => [],
            'row_project_ids' => [10],
            'row_currencies' => ['RUB'],
        ];

        self::assertFalse($policy->matches($request, $source));
        self::assertTrue($policy->matches($request, [
            ...$source,
            'filters' => ['project_ids' => [10]],
        ]));
    }

    #[Test]
    public function it_rejects_conflicting_or_malformed_aliases(): void
    {
        $policy = new ProjectPortfolioHealthOwnerSourcePolicy;
        $request = $this->request();
        $source = $this->source();

        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'currency' => 'USD'],
        ]));
        self::assertFalse($policy->matches($request, [
            ...$source,
            'filters' => [...$source['filters'], 'project_ids' => ['not-an-id']],
        ]));
    }

    /** @return array<string,mixed> */
    private function request(): array
    {
        return [
            'scope_project_ids' => [10, 20],
            'project_ids' => [10, 20],
            'currencies' => ['RUB'],
            'responsibility_center_ids' => [30],
            'responsibility_center_uuids' => ['11111111-1111-4111-8111-111111111111'],
            'counterparty_ids' => [40],
        ];
    }

    /** @return array<string,mixed> */
    private function source(): array
    {
        return [
            'project_id' => null,
            'currency' => 'RUB',
            'filters' => [
                'project_ids' => [20, 10],
                'currencies' => ['rub'],
                'responsibility_center_ids' => [30],
                'counterparty_ids' => [40],
            ],
            'row_project_ids' => [20, 10],
            'row_currencies' => ['rub'],
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotRequest(): array
    {
        return [
            ...$this->request(),
            'organization_id' => 38,
            'kind' => 'project_margin',
            'epm_scope' => 'project_margin',
            'scope_hash' => str_repeat('1', 64),
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-04',
            'as_of' => '2026-08-04T00:00:00+00:00',
            'as_of_date' => '2026-08-04',
            'formula' => 'margin-v1',
            'schema' => 'project_margin_source_v1',
            'parent_formula' => 'budgeting_epm_data_mart_v1',
            'expected_definition_hash' => str_repeat('2', 64),
            'expected_query_hash' => str_repeat('3', 64),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotSource(): array
    {
        return [
            ...$this->source(),
            'filters' => [
                'close_id' => '01K1CLOSE00000000000000000',
                'scenario_uuid' => 'scenario-v1',
                'budget_version_uuid' => 'budget-v1',
                'responsibility_center_id' => '11111111-1111-4111-8111-111111111111',
                'counterparty_id' => 40,
                'group_by' => ['project', 'currency'],
            ],
            'organization_id' => 38,
            'report_code' => 'project_margin',
            'scope_hash' => str_repeat('1', 64),
            'definition_hash' => str_repeat('2', 64),
            'query_hash' => str_repeat('3', 64),
            'source_hash' => str_repeat('4', 64),
            'source_snapshot_kind' => 'budgeting_epm_data_mart',
            'source_snapshot_id' => '01K1SOURCE00000000000000000',
            'source_snapshot_hash' => str_repeat('5', 64),
            'formula' => 'margin-v1',
            'schema' => 'project_margin_source_v1',
            'quality_status' => 'complete',
            'coverage_numerator' => 1,
            'coverage_denominator' => 1,
            'row_count' => 1,
            'rows_count' => 1,
            'row_organization_ids' => [38],
            'row_report_codes' => ['project_margin'],
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-04',
            'as_of' => '2026-08-04T00:00:00+00:00',
            'generated_at' => '2026-08-03T23:00:00+00:00',
            'stale_at' => '2026-08-05T00:00:00+00:00',
            'parent_uuid' => '01K1SOURCE00000000000000000',
            'parent_organization_id' => 38,
            'parent_report_scope' => 'project_margin',
            'parent_scope_hash_valid' => true,
            'parent_status' => 'succeeded',
            'parent_superseded_at' => null,
            'parent_formula' => 'budgeting_epm_data_mart_v1',
            'parent_source_hash' => str_repeat('5', 64),
            'parent_generated_at' => '2026-08-03T23:00:00+00:00',
            'parent_stale_at' => '2026-08-05T00:00:00+00:00',
            'parent_period_from' => '2026-08-01',
            'parent_period_to' => '2026-08-04',
            'parent_as_of_date' => '2026-08-04',
            'budget_version_id' => null,
            'forecast_version_id' => null,
            'closure_hash' => null,
        ];
    }
}
