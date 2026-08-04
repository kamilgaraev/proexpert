<?php

declare(strict_types=1);

namespace Tests\Unit\TimeTracking;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostBuiltinPublishedReport;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostCandidateContract;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostProvider;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostQueryService;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostReportBindingFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectLaborCostPublishedRuntimeBindingRegistrarTest extends TestCase
{
    public function test_registers_real_provider_query_and_signed_drill_contract(): void
    {
        $contract = new ProjectLaborCostCandidateContract;
        $definition = (new ProjectLaborCostBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(ProjectLaborCostProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(ProjectLaborCostQueryService::class))->newInstanceWithoutConstructor();
        $assembler = new ProjectLaborCostCapturingBindingAssembler;
        $registrar = new ProjectLaborCostPublishedRuntimeBindingRegistrar(
            new ProjectLaborCostPublishedRegistry($definition),
            new ProjectLaborCostReportBindingFactory($provider, $query, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[ProjectLaborCostCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'source_refs'], $query->drillDownTokenColumns());
        self::assertSame(['time_tracking.cost.view'], $definition->payload()->permissionPolicy->sensitivePermissions);
    }
}

final class ProjectLaborCostCapturingBindingAssembler implements ReportDefinitionBindingAssembler
{
    /** @var array<string, ReportDefinitionBinding> */
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

final readonly class ProjectLaborCostPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === ProjectLaborCostCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [ProjectLaborCostCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
