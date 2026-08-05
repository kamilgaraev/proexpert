<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DrillDown\ProjectEvmControlDrillDownProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Providers\ProjectEvmControlReportProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Queries\ProjectEvmControlRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness\ProjectControlReadinessProbe;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectEvmControlPublishedContractTest extends TestCase
{
    public function test_contract_keeps_actor_and_tenant_context_server_owned(): void
    {
        $builtin = new ProjectEvmControlBuiltinPublishedReport(new ProjectEvmControlCandidateContract);
        $definition = $builtin->definition()->payload();
        $filterIds = array_column($definition->filters, 'id');

        self::assertSame(
            ['status_date', 'wbs_ids', 'task_ids', 'contractor_ids', 'cost_center_ids', 'currencies'],
            $filterIds,
        );
        self::assertNotContains('organization_id', $filterIds);
        self::assertNotContains('project_id', $filterIds);
        self::assertNotContains('user_id', $filterIds);
        self::assertSame(ProjectEvmControlCandidateContract::FORMULA_VERSION, $definition->formulaVersion);
        self::assertSame(ProjectEvmControlCandidateContract::SOURCE_SCHEMA_VERSION, $definition->sourceSchemaVersion);
        self::assertSame(['reports.project_control.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['reports.project_control.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(
            ['budgeting.wip_forecast.view_sensitive_costs'],
            $definition->permissionPolicy->sensitivePermissions,
        );
        self::assertSame([], $definition->permissionPolicy->auditPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertSame(5, $builtin->metadata()->manifestOrdinal);
        self::assertFalse($builtin->scheduling()->supportsSubscriptions);
        self::assertFalse($builtin->scheduling()->reproducibleScheduledSnapshot);
    }

    public function test_runtime_binding_uses_immutable_projection_and_signed_drill(): void
    {
        $contract = new ProjectEvmControlCandidateContract;
        $definition = (new ProjectEvmControlBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(ProjectEvmControlReportProvider::class))->newInstanceWithoutConstructor();
        $rows = new ProjectEvmControlRowQuery;
        $drillDown = new ProjectEvmControlDrillDownProvider;
        $readiness = (new ReflectionClass(ProjectControlReadinessProbe::class))->newInstanceWithoutConstructor();
        $assembler = new ProjectEvmControlCapturingBindingAssembler;
        $registrar = new ProjectEvmControlPublishedRuntimeBindingRegistrar(
            new ProjectEvmControlPublishedRegistry($definition),
            new ProjectEvmControlReportBindingFactory(
                $provider,
                $rows,
                $drillDown,
                $readiness,
                $contract,
            ),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[ProjectEvmControlCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($rows, $binding->rowQuery);
        self::assertSame($drillDown, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }
}

final class ProjectEvmControlCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class ProjectEvmControlPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === ProjectEvmControlCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [ProjectEvmControlCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
