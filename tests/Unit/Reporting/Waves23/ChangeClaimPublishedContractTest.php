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
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimBuiltinPublishedReport;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimCandidateContract;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimReportBindingFactory;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness\ChangeClaimReadinessProbe;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ChangeClaimPublishedContractTest extends TestCase
{
    public function test_published_definition_preserves_verified_r14_contract(): void
    {
        $published = new ChangeClaimBuiltinPublishedReport(new ChangeClaimCandidateContract);
        $definition = $published->definition()->payload();

        self::assertSame('change_claim_contingency', $definition->code);
        self::assertSame('change-claim-contingency.v1', $definition->formulaVersion);
        self::assertSame('change-claim-history.v1', $definition->sourceSchemaVersion);
        self::assertSame('published', $definition->publicationReadiness->value);
        self::assertSame(14, $published->metadata()->manifestOrdinal);
        self::assertFalse($published->scheduling()->supportsSubscriptions);
    }

    public function test_authoritative_registries_and_run_route_include_r14(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            'BuiltinPublishedReportDefinitionRegistry.php',
            'BuiltinReportCatalogMetadataRegistry.php',
            'BuiltinReportSchedulingCapabilityRegistry.php',
        ] as $file) {
            $source = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Catalog/'.$file);
            self::assertStringContainsString('ChangeClaimBuiltinPublishedReport', $source);
        }
        $catalogProvider = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php');
        self::assertStringContainsString('ChangeClaimBuiltinPublishedReport::class', $catalogProvider);
        self::assertStringContainsString('$app->make(ChangeClaimBuiltinPublishedReport::class)', $catalogProvider);
        $routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
        self::assertStringContainsString("Route::post('/change-claim-contingency/runs'", $routes);
        self::assertStringContainsString("->defaults('reportCode', 'change_claim_contingency')", $routes);
    }

    public function test_runtime_binding_registers_existing_r14_runtime(): void
    {
        $contract = new ChangeClaimCandidateContract;
        $definition = (new ChangeClaimBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(ChangeClaimContingencyReportProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(ChangeClaimRowQuery::class))->newInstanceWithoutConstructor();
        $drillDown = (new ReflectionClass(ChangeClaimDrillDownProvider::class))->newInstanceWithoutConstructor();
        $readiness = (new ReflectionClass(ChangeClaimReadinessProbe::class))->newInstanceWithoutConstructor();
        $assembler = new ChangeClaimCapturingBindingAssembler;
        $registrar = new ChangeClaimPublishedRuntimeBindingRegistrar(
            new ChangeClaimPublishedRegistry($definition),
            new ChangeClaimReportBindingFactory($provider, $query, $drillDown, $readiness, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings['change_claim_contingency'];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($drillDown, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }

    public function test_provider_checks_readiness_before_materialization(): void
    {
        $root = dirname(__DIR__, 4);
        $provider = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Providers/ChangeClaimContingencyReportProvider.php');

        $guard = strpos($provider, '$this->readiness->assertRunnable($context, $query);');
        $materialization = strpos($provider, '$this->materializer->materialize($context->scope, $query);');
        self::assertIsInt($guard);
        self::assertIsInt($materialization);
        self::assertLessThan($materialization, $guard);
    }
}

final class ChangeClaimCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class ChangeClaimPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === 'change_claim_contingency'
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return ['change_claim_contingency'];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
