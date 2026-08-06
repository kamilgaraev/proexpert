<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementProjectionService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureRecord;
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

    public function test_typed_party_storage_is_positive_and_not_publicly_exportable(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/migrations/'
            .'2026_08_06_000100_add_typed_party_identity_to_contract_settlement_report.php',
        );
        self::assertIsString($migration);
        self::assertSame(2, substr_count($migration, 'party_id > 0'));
        self::assertStringContainsString('contract_settlement_row_party_idx', $migration);

        $translations = require $root.'/lang/ru/reports.php';
        self::assertSame(
            'Контрагент',
            $translations['contract_settlement_exposure']['columns']['party'],
        );

        $reflection = new ReflectionClass(ContractSettlementQueryService::class);
        $query = $reflection->newInstanceWithoutConstructor();
        $record = new ContractSettlementExposureRecord;
        $record->forceFill([
            'row_key' => str_repeat('a', 64),
            'contract_id' => 10,
            'allocation_id' => 20,
            'project_id' => 30,
            'party_id' => 40,
            'party_type' => 'contractor',
            'party_key' => 'contractor:40',
            'party_label' => 'Подрядчик по договору Д-1',
            'direction' => 'payable',
            'currency' => 'RUB',
            'currency_source' => 'contract_payment_owner',
            'effective_minor' => 100_000,
            'accepted_minor' => 50_000,
            'cash_minor' => 30_000,
            'settlement_minor' => 20_000,
            'unperformed_exposure_minor' => 50_000,
            'unpaid_exposure_minor' => 20_000,
            'aging_bucket' => 'days_31_60',
        ]);
        $row = $reflection->getMethod('serialize')->invoke($query, $record);
        self::assertSame('Подрядчик по договору Д-1', $row['party']);
        self::assertArrayNotHasKey('party_id', $row);
        self::assertArrayNotHasKey('party_type', $row);
        self::assertArrayNotHasKey('party_key', $row);
    }
}
