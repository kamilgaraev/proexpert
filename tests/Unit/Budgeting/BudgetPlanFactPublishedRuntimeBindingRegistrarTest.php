<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;

final class BudgetPlanFactPublishedRuntimeBindingRegistrarTest extends TestCase
{
    public function test_registers_the_sealed_snapshot_provider_for_the_published_definition(): void
    {
        $definition = $this->definition();
        $adapter = (new ReflectionClass(PlanFactReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor();
        $assembler = new CapturingReportDefinitionBindingAssembler;
        $registrar = new BudgetPlanFactPublishedRuntimeBindingRegistrar(
            new PublishedBudgetPlanFactRegistry(new PublishedReportDefinition($definition)),
            new BudgetPlanFactReportBindingFactory($adapter, new BudgetPlanFactCandidateContract),
        );

        $registrar->register($assembler);

        self::assertCount(1, $assembler->bindings);
        $binding = $assembler->bindings[BudgetPlanFactCandidateContract::CODE];
        self::assertSame($adapter, $binding->dataProvider);
        self::assertSame($adapter, $binding->rowQuery);
        self::assertSame($adapter, $binding->drillDownProvider);
        self::assertSame($definition->definitionHash->value, $binding->definitionHash->value);
    }

    public function test_does_not_register_a_provider_before_publication_admission(): void
    {
        $assembler = new CapturingReportDefinitionBindingAssembler;
        $registrar = new BudgetPlanFactPublishedRuntimeBindingRegistrar(
            new UnpublishedBudgetPlanFactRegistry,
            new BudgetPlanFactReportBindingFactory(
                (new ReflectionClass(PlanFactReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor(),
                new BudgetPlanFactCandidateContract,
            ),
        );

        $registrar->register($assembler);

        self::assertSame([], $assembler->bindings);
    }

    private function definition(): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition
    {
        $contract = new BudgetPlanFactCandidateContract;

        return (new ReportDefinitionBuilder)
            ->code(BudgetPlanFactCandidateContract::CODE)
            ->contractVersion('1.0.0')
            ->formulaVersion(BudgetPlanFactCandidateContract::FORMULA_VERSION)
            ->sourceSchemaVersion($contract->sourceSchemaVersion)
            ->filters($contract->filters())
            ->columns($contract->columns())
            ->sorts($contract->sorts())
            ->formats($contract->formats())
            ->permissionPolicy(new ReportPermissionPolicy(['budgeting.plan_fact.view'], ['budgeting.plan_fact.export'], [], []))
            ->payload();
    }
}

final class CapturingReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class PublishedBudgetPlanFactRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        if ($code !== BudgetPlanFactCandidateContract::CODE) {
            throw new LogicException('unexpected_report_code');
        }

        return $this->definition;
    }

    public function publishedCodes(): array
    {
        return [BudgetPlanFactCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}

final class UnpublishedBudgetPlanFactRegistry implements ReportDefinitionRegistry
{
    public function published(string $code): PublishedReportDefinition
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
