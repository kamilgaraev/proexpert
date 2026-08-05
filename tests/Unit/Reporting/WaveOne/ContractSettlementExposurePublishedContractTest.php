<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureProvider;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposurePublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureReportBindingFactory;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContractSettlementExposurePublishedContractTest extends TestCase
{
    public function test_contract_keeps_actor_and_tenant_context_server_owned(): void
    {
        $definition = (new ContractSettlementExposureBuiltinPublishedReport(
            new ContractSettlementExposureCandidateContract,
        ))->definition()->payload();

        self::assertSame([
            'contract_ids', 'project_ids', 'allocation_ids', 'party_ids', 'directions',
            'instruments', 'statuses', 'due_from', 'due_to', 'currencies', 'period_from',
            'period_to', 'aging_buckets',
        ], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_id', array_column($definition->filters, 'id'));
        self::assertNotContains('user_id', array_column($definition->filters, 'id'));
        self::assertSame(['contracts.management_report.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['contracts.management_report.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame([], $definition->permissionPolicy->sensitivePermissions);
        self::assertSame([], $definition->permissionPolicy->auditPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
    }

    public function test_runtime_binding_uses_projection_query_and_signed_drill(): void
    {
        $contract = new ContractSettlementExposureCandidateContract;
        $definition = (new ContractSettlementExposureBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(ContractSettlementExposureProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(ContractSettlementQueryService::class))->newInstanceWithoutConstructor();
        $assembler = new ContractSettlementExposureCapturingBindingAssembler;
        $registrar = new ContractSettlementExposurePublishedRuntimeBindingRegistrar(
            new ContractSettlementExposurePublishedRegistry($definition),
            new ContractSettlementExposureReportBindingFactory($provider, $query, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[ContractSettlementExposureCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'drill'], $query->drillDownTokenColumns());
    }
}

final class ContractSettlementExposureCapturingBindingAssembler implements ReportDefinitionBindingAssembler
{
    public array $bindings = [];

    public function register(ReportDefinitionBinding $binding): void
    {
        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        throw new LogicException('not_used');
    }
}

final readonly class ContractSettlementExposurePublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === ContractSettlementExposureCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [ContractSettlementExposureCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
