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
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ManagementPnlPublishedContractTest extends TestCase
{
    public function test_published_definition_preserves_verified_r13_contract(): void
    {
        $published = new ManagementPnlBuiltinPublishedReport(new ManagementPnlCandidateContract);
        $definition = $published->definition()->payload();

        self::assertSame('management_pnl', $definition->code);
        self::assertSame('management-pnl.v1', $definition->formulaVersion);
        self::assertSame('management-pnl-components.v1', $definition->sourceSchemaVersion);
        self::assertSame('published', $definition->publicationReadiness->value);
        self::assertSame(13, $published->metadata()->manifestOrdinal);
        self::assertFalse($published->scheduling()->supportsSubscriptions);
    }

    public function test_authoritative_registries_and_run_route_include_r13(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            'BuiltinPublishedReportDefinitionRegistry.php',
            'BuiltinReportCatalogMetadataRegistry.php',
            'BuiltinReportSchedulingCapabilityRegistry.php',
        ] as $file) {
            $source = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/'.$file);
            self::assertStringContainsString('ManagementPnlBuiltinPublishedReport', $source);
        }
        $routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
        self::assertStringContainsString("Route::post('/management-pnl/runs'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'management_pnl')", $routes);
    }

    public function test_runtime_binding_registers_existing_r13_runtime(): void
    {
        $contract = new ManagementPnlCandidateContract;
        $definition = (new ManagementPnlBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(ManagementPnlProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(ManagementPnlQueryService::class))->newInstanceWithoutConstructor();
        $readiness = (new ReflectionClass(ManagementPnlReadinessProbe::class))->newInstanceWithoutConstructor();
        $assembler = new ManagementPnlCapturingBindingAssembler;
        $registrar = new ManagementPnlPublishedRuntimeBindingRegistrar(
            new ManagementPnlPublishedRegistry($definition),
            new ManagementPnlReportBindingFactory($provider, $query, $readiness, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings['management_pnl'];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }

    public function test_provider_checks_readiness_before_materialization(): void
    {
        $root = dirname(__DIR__, 4);
        $provider = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/Reporting/ManagementPnl/ManagementPnlProvider.php');

        $guard = strpos($provider, '$this->readiness->assertRunnable($context, $query);');
        $policyLookup = strpos($provider, 'ManagementPnlPolicy::query()');
        self::assertIsInt($guard);
        self::assertIsInt($policyLookup);
        self::assertLessThan($policyLookup, $guard);
    }
}

final class ManagementPnlCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class ManagementPnlPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === 'management_pnl'
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return ['management_pnl'];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
