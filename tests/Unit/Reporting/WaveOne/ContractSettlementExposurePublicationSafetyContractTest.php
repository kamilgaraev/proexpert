<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementProjectionService;
use App\Enums\CurrencyCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContractSettlementExposurePublicationSafetyContractTest extends TestCase
{
    public function test_runtime_route_binds_server_organization_before_authorization(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/routes.php');
        $middlewareFile = (new ReflectionClass(AuthorizeReportDefinitionAccess::class))->getFileName();
        self::assertIsString($routes);
        self::assertIsString($middlewareFile);
        $middleware = file_get_contents($middlewareFile);

        self::assertIsString($middleware);
        self::assertStringContainsString("Route::post('/contract-settlement-exposure/runs'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'contract_settlement_exposure')", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertStringContainsString("'admin.reports.contract-settlement-exposure.runs.store'", $middleware);
    }

    public function test_owner_source_enforces_object_scope_and_shared_currency_enum(): void
    {
        $ownerSourceFile = (new ReflectionClass(ContractSettlementOwnerSource::class))->getFileName();
        $projectionFile = (new ReflectionClass(ContractSettlementProjectionService::class))->getFileName();
        $currencyFile = (new ReflectionClass(CurrencyCode::class))->getFileName();
        self::assertIsString($ownerSourceFile);
        self::assertIsString($projectionFile);
        self::assertIsString($currencyFile);
        $ownerSource = file_get_contents($ownerSourceFile);
        $projection = file_get_contents($projectionFile);

        self::assertIsString($ownerSource);
        self::assertIsString($projection);
        self::assertStringContainsString('FinanceSourceAccessPolicy', $projection);
        self::assertStringContainsString('->allowsAggregate(', $projection);
        self::assertStringContainsString('CurrencyCode::tryFrom($currency)', $ownerSource);
        self::assertStringContainsString('CurrencyCode::tryFrom($currency)', $projection);
        self::assertStringContainsString("'organization_id'", $ownerSource);
        self::assertStringContainsString('assertOrganizationFilter($scope, $query->filters->values)', $ownerSource);
        self::assertStringContainsString("throw new DomainException('report_projection_scope_invalid')", $ownerSource);
    }
}
