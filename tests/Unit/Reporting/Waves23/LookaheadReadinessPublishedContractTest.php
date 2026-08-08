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
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DrillDown\LookaheadReadinessDrillDownProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessReportBindingFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Providers\LookaheadReadinessReportProvider;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Queries\LookaheadReadinessRowQuery;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LookaheadReadinessPublishedContractTest extends TestCase
{
    public function test_published_definition_preserves_verified_r07_contract(): void
    {
        $published = new LookaheadReadinessBuiltinPublishedReport(new LookaheadReadinessCandidateContract);
        $definition = $published->definition()->payload();

        self::assertSame('lookahead_readiness', $definition->code);
        self::assertSame('lookahead_readiness.v1', $definition->formulaVersion);
        self::assertSame('lookahead_events_v1', $definition->sourceSchemaVersion);
        self::assertSame('published', $definition->publicationReadiness->value);
        self::assertSame(7, $published->metadata()->manifestOrdinal);
        self::assertFalse($published->scheduling()->supportsSubscriptions);
    }

    public function test_authoritative_registries_and_project_run_route_include_r07(): void
    {
        $root = dirname(__DIR__, 4);
        $definitionRegistry = file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/BuiltinPublishedReportDefinitionRegistry.php');
        $metadataRegistry = file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/BuiltinReportCatalogMetadataRegistry.php');
        $schedulingRegistry = file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/BuiltinReportSchedulingCapabilityRegistry.php');
        $catalogProvider = file_get_contents($root.'/app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php');
        $routes = file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');

        foreach ([$definitionRegistry, $metadataRegistry, $schedulingRegistry] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('LookaheadReadinessBuiltinPublishedReport', $source);
        }
        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/projects/{project}/lookahead-readiness/runs'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'lookahead_readiness')", $routes);
        self::assertIsString($catalogProvider);
        self::assertStringContainsString('LookaheadReadinessBuiltinPublishedReport::class', $catalogProvider);
        self::assertStringContainsString('$app->make(LookaheadReadinessBuiltinPublishedReport::class)', $catalogProvider);
    }

    public function test_runtime_binding_registers_the_verified_r07_components(): void
    {
        $contract = new LookaheadReadinessCandidateContract;
        $definition = (new LookaheadReadinessBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(LookaheadReadinessReportProvider::class))->newInstanceWithoutConstructor();
        $rows = (new ReflectionClass(LookaheadReadinessRowQuery::class))->newInstanceWithoutConstructor();
        $drillDown = new LookaheadReadinessDrillDownProvider;
        $readiness = (new ReflectionClass(LookaheadReadinessProbe::class))->newInstanceWithoutConstructor();
        $assembler = new LookaheadReadinessCapturingBindingAssembler;
        $registrar = new LookaheadReadinessPublishedRuntimeBindingRegistrar(
            new LookaheadReadinessPublishedRegistry($definition),
            new LookaheadReadinessReportBindingFactory(
                $provider,
                $rows,
                $drillDown,
                $readiness,
                $contract,
            ),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[LookaheadReadinessCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($rows, $binding->rowQuery);
        self::assertSame($drillDown, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }
}

final class LookaheadReadinessCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class LookaheadReadinessPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === LookaheadReadinessCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [LookaheadReadinessCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
