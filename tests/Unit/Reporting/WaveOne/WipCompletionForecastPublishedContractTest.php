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
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastReportBindingFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WipCompletionForecastPublishedContractTest extends TestCase
{
    public function test_contract_keeps_actor_and_tenant_context_server_owned(): void
    {
        $definition = (new WipCompletionForecastBuiltinPublishedReport(
            new WipCompletionForecastCandidateContract,
        ))->definition()->payload();

        self::assertSame(['period_start', 'period_end'], array_column($definition->filters, 'id'));
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_id', array_column($definition->filters, 'id'));
        self::assertNotContains('user_id', array_column($definition->filters, 'id'));
        self::assertSame(['budgeting.wip_forecast.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['budgeting.wip_forecast.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['budgeting.wip_forecast.view_sensitive_costs'], $definition->permissionPolicy->sensitivePermissions);
        self::assertSame(['budgeting.wip_forecast.view_audit'], $definition->permissionPolicy->auditPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
    }

    public function test_runtime_binding_uses_projection_query_and_signed_drill(): void
    {
        $contract = new WipCompletionForecastCandidateContract;
        $definition = (new WipCompletionForecastBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(WipCompletionForecastProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(ProjectFinanceQueryService::class))->newInstanceWithoutConstructor();
        $assembler = new WipCompletionForecastCapturingBindingAssembler;
        $registrar = new WipCompletionForecastPublishedRuntimeBindingRegistrar(
            new WipCompletionForecastPublishedRegistry($definition),
            new WipCompletionForecastReportBindingFactory($provider, $query, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[WipCompletionForecastCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'source_refs'], $query->drillDownTokenColumns());
    }
}

final class WipCompletionForecastCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class WipCompletionForecastPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === WipCompletionForecastCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [WipCompletionForecastCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
