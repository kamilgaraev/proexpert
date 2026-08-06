<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ContractSettlementExposureReportOptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Support\ReportAsOfParser;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureOptionsService;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\BusinessModules\Features\ContractManagement\Reporting\Enums\ContractSettlementPartyType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContractSettlementExposureOptionsContractTest extends TestCase
{
    public function test_options_route_uses_server_owned_organization_scope(): void
    {
        $routes = file_get_contents(base_path('app/BusinessModules/Core/Reporting/routes.php'));
        $middleware = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/AuthorizeReportDefinitionAccess.php',
        ));
        $controller = file_get_contents(base_path(
            'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ContractSettlementExposureReportOptionsController.php',
        ));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/contract-settlement-exposure/options'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'contract_settlement_exposure')", $routes);
        self::assertStringContainsString("->middleware(['report.organization-scope', \$resourceAccess])", $routes);
        self::assertIsString($middleware);
        self::assertStringContainsString("'admin.reports.contract-settlement-exposure.options'", $middleware);
        self::assertIsString($controller);
        self::assertStringContainsString('ContractSettlementExposureCandidateContract::CODE', $controller);
        self::assertStringContainsString('$context->scope', $controller);
        self::assertStringNotContainsString("input('organization_id')", $controller);
        self::assertStringNotContainsString("input('project_id')", $controller);
    }

    public function test_options_reject_client_context_and_require_exact_as_of(): void
    {
        $rules = (new ContractSettlementExposureReportOptionsRequest)->rules();

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
        self::assertNotNull(ReportAsOfParser::parse('2026-08-06T14:15:16.123456+03:00'));
        self::assertNull(ReportAsOfParser::parse('2026-08-06'));
    }

    public function test_options_are_assembled_from_the_same_point_in_time_owner_source(): void
    {
        $source = file_get_contents((new ReflectionClass(
            ContractSettlementExposureOptionsService::class,
        ))->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString('$this->source->read($scope, $query)', $source);
        self::assertStringContainsString("'contract_settlement_owner_versions'", $source);
        self::assertStringContainsString("->where('organization_id', \$scope->organizationId)", $source);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::database($asOf)', $source);
        self::assertStringContainsString('CurrencyCode::options()', $source);
        self::assertStringContainsString('$this->calculator->calculate(', $source);
        self::assertStringNotContainsString("input('organization_id')", $source);
    }

    public function test_options_read_uses_a_read_only_repeatable_read_snapshot(): void
    {
        $source = $this->methodSource('options');

        self::assertStringContainsString('$this->connection->transactionLevel()', $source);
        self::assertStringContainsString('$this->connection->transaction(', $source);
        self::assertStringContainsString(
            "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY",
            $source,
        );
        self::assertStringContainsString('$this->optionsWithinStableView(', $source);
    }

    public function test_owner_payloads_select_only_latest_as_of_version(): void
    {
        $source = $this->methodSource('ownerPayloads');

        self::assertStringContainsString("selectRaw('DISTINCT ON (owner_id) id')", $source);
        self::assertStringContainsString("->whereIn('id', \$latestIds)", $source);
        self::assertStringNotContainsString('array_key_exists($ownerId, $payloads)', $source);
    }

    public function test_project_options_use_authorized_scope_for_shared_projects(): void
    {
        $source = $this->methodSource('projectOptions');

        self::assertStringContainsString('array_intersect($ids, $scope->projectIds)', $source);
        self::assertStringNotContainsString("->where('organization_id', \$scope->organizationId)", $source);
    }

    public function test_party_options_keep_namespaces_and_point_in_time_labels(): void
    {
        $service = (new ReflectionClass(ContractSettlementExposureOptionsService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'partyOptions');
        $options = $method->invoke($service, [
            $this->input(101, 1001, ContractSettlementPartyType::CONTRACTOR, 'Подрядчик по договору №1'),
            $this->input(102, 1002, ContractSettlementPartyType::CONTRACTOR, 'Подрядчик по договору №2'),
            $this->input(103, 1003, ContractSettlementPartyType::SUPPLIER, 'Поставщик по договору №3'),
        ]);

        self::assertIsArray($options);
        self::assertCount(2, $options);
        $byId = array_column($options, null, 'id');
        self::assertSame(
            'Подрядчик по договору №1 / Подрядчик по договору №2',
            $byId['contractor:40']['name'],
        );
        self::assertSame([101, 102], $byId['contractor:40']['contract_ids']);
        self::assertSame('Поставщик по договору №3', $byId['supplier:40']['name']);
        self::assertSame([103], $byId['supplier:40']['contract_ids']);
    }

    public function test_options_never_read_live_party_directories_or_expose_numeric_party_filters(): void
    {
        $source = file_get_contents((new ReflectionClass(
            ContractSettlementExposureOptionsService::class,
        ))->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString('$input->partyKey()', $source);
        self::assertStringContainsString('$input->partyLabel', $source);
        self::assertStringContainsString("'party_key' => \$input->partyKey()", $source);
        self::assertStringContainsString("'party_keys' => array_keys(\$partyKeys)", $source);
        self::assertStringNotContainsString("'contractors' => 'contractor_id'", $source);
        self::assertStringNotContainsString("'suppliers' => 'supplier_id'", $source);
        self::assertStringNotContainsString("'party_id' =>", $source);
        self::assertStringNotContainsString("'party_ids' =>", $source);
    }

    public function test_every_runtime_option_has_a_business_facing_russian_label(): void
    {
        $translations = require base_path('lang/ru/reports.php');
        $options = $translations['options']['contract_settlement_exposure'];

        foreach (PaymentDocumentType::cases() as $type) {
            self::assertNotSame('', trim((string) ($options['instruments'][$type->value] ?? '')));
        }
        foreach (PaymentDocumentStatus::cases() as $status) {
            self::assertNotSame('', trim((string) ($options['statuses'][$status->value] ?? '')));
        }
        foreach (SettlementAgingBucket::cases() as $bucket) {
            self::assertNotSame('', trim((string) ($options['aging_buckets'][$bucket->value] ?? '')));
        }
        foreach (['receivable', 'payable'] as $direction) {
            self::assertNotSame('', trim((string) ($options['directions'][$direction] ?? '')));
        }
        self::assertNotSame('', trim((string) ($options['aging_buckets']['due_date_missing'] ?? '')));
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(ContractSettlementExposureOptionsService::class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    private function input(
        int $contractId,
        int $allocationId,
        ContractSettlementPartyType $partyType,
        string $partyLabel,
    ): ContractSettlementInput {
        return new ContractSettlementInput(
            contractId: $contractId,
            allocationId: $allocationId,
            projectId: 501,
            partyId: 40,
            partyType: $partyType,
            partyLabel: $partyLabel,
            direction: 'payable',
            currency: 'RUB',
            effectiveMinor: 100,
            acceptedMinor: 50,
            cashMinor: 25,
            dueAt: null,
            asOf: new DateTimeImmutable('2026-08-06T00:00:00+00:00'),
            sourceRefs: [],
        );
    }
}
