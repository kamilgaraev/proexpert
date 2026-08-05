<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowReportBindingFactory;
use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\IntercompanyContractFlowsReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\IntercompanyContractFlowRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Readiness\IntercompanyContractFlowReadinessProbe;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IntercompanyContractFlowPublishedContractTest extends TestCase
{
    #[Test]
    public function published_contract_keeps_tenant_identity_server_owned(): void
    {
        $builtin = new IntercompanyContractFlowBuiltinPublishedReport(new IntercompanyContractFlowCandidateContract);
        $definition = $builtin->definition()->payload();
        $filterIds = array_column($definition->filters, 'id');

        self::assertSame([
            'project_ids',
            'organization_ids',
            'counterparty_ids',
            'work_type_categories',
            'contract_ids',
            'currencies',
            'period_from',
            'period_to',
        ], $filterIds);
        self::assertNotContains('organization_id', $filterIds);
        self::assertNotContains('project_id', $filterIds);
        self::assertNotContains('user_id', $filterIds);
        self::assertSame(IntercompanyContractFlowCandidateContract::FORMULA_VERSION, $definition->formulaVersion);
        self::assertSame(IntercompanyContractFlowCandidateContract::SOURCE_SCHEMA_VERSION, $definition->sourceSchemaVersion);
        self::assertSame(['multi-organization.reports.financial'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['multi-organization.reports.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame([], $definition->permissionPolicy->sensitivePermissions);
        self::assertSame([], $definition->permissionPolicy->auditPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertSame(3, $builtin->metadata()->manifestOrdinal);
        self::assertFalse($builtin->scheduling()->supportsSubscriptions);
        self::assertFalse($builtin->scheduling()->reproducibleScheduledSnapshot);
    }

    #[Test]
    public function published_runtime_binding_uses_checkpoint_projection_and_scoped_drill_down(): void
    {
        $contract = new IntercompanyContractFlowCandidateContract;
        $definition = (new IntercompanyContractFlowBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(IntercompanyContractFlowsReportProvider::class))
            ->newInstanceWithoutConstructor();
        $rows = (new ReflectionClass(IntercompanyContractFlowRowQuery::class))
            ->newInstanceWithoutConstructor();
        $readiness = (new ReflectionClass(IntercompanyContractFlowReadinessProbe::class))
            ->newInstanceWithoutConstructor();
        $assembler = new IntercompanyContractFlowCapturingBindingAssembler;
        $registrar = new IntercompanyContractFlowPublishedRuntimeBindingRegistrar(
            new IntercompanyContractFlowPublishedRegistry($definition),
            new IntercompanyContractFlowReportBindingFactory($provider, $rows, $readiness, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[IntercompanyContractFlowCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($rows, $binding->rowQuery);
        self::assertSame($rows, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }
}

final class IntercompanyContractFlowCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class IntercompanyContractFlowPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === IntercompanyContractFlowCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [IntercompanyContractFlowCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
