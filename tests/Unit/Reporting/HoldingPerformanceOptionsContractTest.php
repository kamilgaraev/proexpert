<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceOptionsService;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceOptionDimensionQuery;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\HoldingPerformanceReportOptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use Tests\TestCase;

final class HoldingPerformanceOptionsContractTest extends TestCase
{
    public function test_routes_use_the_dedicated_holding_contract_and_server_scope(): void
    {
        $routes = file_get_contents(base_path('app/BusinessModules/Core/Reporting/routes.php'));
        $middleware = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/AuthorizeReportDefinitionAccess.php',
        ));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/holding-performance/options'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'holding_performance')", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertIsString($middleware);
        self::assertStringContainsString("'admin.reports.holding-performance.options'", $middleware);
    }

    public function test_options_use_the_runtime_opening_checkpoint_and_only_server_scope_references(): void
    {
        $source = file_get_contents((new ReflectionClass(HoldingPerformanceOptionsService::class))->getFileName());
        $dimensionQuery = file_get_contents(
            (new ReflectionClass(HoldingPerformanceOptionDimensionQuery::class))->getFileName(),
        );
        $controller = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/HoldingPerformanceReportOptionsController.php',
        ));

        self::assertIsString($source);
        self::assertStringContainsString('$this->events->coverageStartedAt(', $source);
        self::assertStringContainsString('$this->connection->transactionLevel() > 0', $source);
        self::assertStringContainsString('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', $source);
        self::assertStringContainsString('$this->optionsWithinStableView(', $source);
        self::assertStringContainsString('$this->events->assertPeriodCovered(', $source);
        self::assertStringContainsString('$this->sources->openingBoundary($query)', $source);
        self::assertStringContainsString(
            '$this->sources->assembleOpeningState($scope, $query, $openingBoundary)',
            $source,
        );
        self::assertStringContainsString('$batch->hierarchy->organizationIds', $source);
        self::assertStringContainsString('$scope->projectIds', $source);
        self::assertStringContainsString('$this->optionDimensions->resolve(', $source);
        self::assertStringContainsString("! \$eventDimensions['complete']", $source);
        self::assertStringContainsString('$this->eventFallsWithinPeriod(', $source);
        self::assertStringNotContainsString('->persist(', $source);
        self::assertStringNotContainsString('->recordGap(', $source);
        self::assertStringNotContainsString('->synchronize(', $source);
        self::assertStringContainsString("->table('contractors')", $source);
        self::assertStringContainsString("->whereIn('organization_id', \$organizationIds)", $source);
        self::assertStringContainsString('ContractStatusEnum::tryFrom($code)', $source);
        self::assertStringContainsString('CurrencyCode::options()', $source);
        self::assertStringNotContainsString("input('organization_id')", $source);
        self::assertStringNotContainsString("input('project_id')", $source);

        self::assertIsString($dimensionQuery);
        self::assertStringContainsString("->leftJoinLateral(\$hierarchy, 'event_hierarchy')", $dimensionQuery);
        self::assertStringContainsString("->leftJoinLateral(\$hierarchyState, 'hierarchy_state')", $dimensionQuery);
        self::assertStringContainsString("->leftJoinLateral(\$dimension, 'event_dimension')", $dimensionQuery);
        self::assertStringContainsString("->leftJoinLateral(\$allocation, 'event_allocation')", $dimensionQuery);
        self::assertStringContainsString('holding_accepted_work_event_versions as ', $dimensionQuery);
        self::assertStringContainsString('holding_payment_transaction_event_versions as ', $dimensionQuery);

        self::assertIsString($controller);
        self::assertStringContainsString(
            '->createRun($request, HoldingPerformanceCandidateContract::CODE)',
            $controller,
        );
        self::assertStringContainsString('$context->scope', $controller);
        self::assertStringNotContainsString("input('organization_id')", $controller);
        self::assertStringNotContainsString("input('project_id')", $controller);
    }

    public function test_options_reject_client_context_and_accept_only_exact_business_dates(): void
    {
        $request = new HoldingPerformanceReportOptionsRequest;
        $rules = $request->rules();

        foreach ([
            'organization_id',
            'current_organization_id',
            'holding_organization_ids',
            'organization_ids',
            'project_id',
            'current_project_id',
            'project_ids',
            'user_id',
            'actor_id',
            'scope',
            'permissions',
        ] as $field) {
            self::assertContains('prohibited', $rules[$field]);
        }
        self::assertSame(['required', 'string'], array_slice($rules['as_of'], 0, 2));
        self::assertSame(['nullable', 'string', 'date_format:Y-m-d'], $rules['period_from']);
        self::assertSame(
            ['nullable', 'string', 'date_format:Y-m-d'],
            array_slice($rules['period_to'], 0, 3),
        );
        self::assertNotNull(ReportAsOfParser::parse('2026-08-05T14:15:16.123456+03:00'));
        self::assertNull(ReportAsOfParser::parse('2026-08-05'));
    }

    public function test_validator_rejects_every_client_owned_context_field(): void
    {
        $request = new HoldingPerformanceReportOptionsRequest;
        $payload = [
            'as_of' => '2026-08-05T14:15:16.123456+03:00',
            'organization_id' => 38,
            'current_organization_id' => 38,
            'holding_organization_ids' => [38],
            'organization_ids' => [38],
            'project_id' => 91,
            'current_project_id' => 91,
            'project_ids' => [91],
            'user_id' => 17,
            'actor_id' => 17,
            'scope' => ['organization_id' => 38],
            'permissions' => ['multi-organization.reports.kpi'],
        ];
        $validator = Validator::make($payload, $request->rules());

        self::assertTrue($validator->fails());
        foreach (array_keys($payload) as $field) {
            if ($field !== 'as_of') {
                self::assertArrayHasKey($field, $validator->errors()->toArray());
            }
        }
    }
}
