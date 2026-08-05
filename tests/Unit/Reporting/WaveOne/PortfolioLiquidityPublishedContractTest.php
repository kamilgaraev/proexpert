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
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityProvider;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityReportBindingFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PortfolioLiquidityPublishedContractTest extends TestCase
{
    public function test_published_contract_keeps_tenant_context_server_owned(): void
    {
        $definition = (new PortfolioLiquidityBuiltinPublishedReport(
            new PortfolioLiquidityCandidateContract,
        ))->definition()->payload();

        self::assertSame([
            'as_of',
            'horizon_from',
            'horizon_to',
            'project_ids',
            'responsibility_center_ids',
            'counterparty_ids',
            'document_ids',
            'scenarios',
            'currencies',
        ], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('user_id', array_column($definition->filters, 'id'));
        self::assertSame(['budgeting.cfo.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['budgeting.cash_gap.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
    }

    public function test_runtime_binding_uses_real_provider_query_and_signed_drill(): void
    {
        $contract = new PortfolioLiquidityCandidateContract;
        $definition = (new PortfolioLiquidityBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(PortfolioLiquidityProvider::class))->newInstanceWithoutConstructor();
        $query = new BudgetingPortfolioQueryService;
        $assembler = new PortfolioLiquidityCapturingBindingAssembler;
        $registrar = new PortfolioLiquidityPublishedRuntimeBindingRegistrar(
            new PortfolioLiquidityPublishedRegistry($definition),
            new PortfolioLiquidityReportBindingFactory($provider, $query, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[PortfolioLiquidityCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'source_refs'], $query->drillDownTokenColumns());
    }
}

final class PortfolioLiquidityCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class PortfolioLiquidityPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === PortfolioLiquidityCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [PortfolioLiquidityCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
