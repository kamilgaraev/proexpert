<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownTokenColumns;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionPublishedRuntimeBindingRegistrar;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionReportBindingFactory;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DrillDown\AcceptedProductionDrillDownProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Queries\AcceptedProductionRowQuery;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness\AcceptedProductionReadinessProbe;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AcceptedProductionPublishedContractTest extends TestCase
{
    public function test_contract_keeps_tenant_and_project_context_server_owned(): void
    {
        $builtin = new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract);
        $definition = $builtin->definition()->payload();
        $filterIds = array_column($definition->filters, 'id');

        self::assertSame([
            'period_from',
            'period_to',
            'work_ids',
            'act_ids',
            'contractor_ids',
            'unit_codes',
            'zones',
            'statuses',
        ], $filterIds);
        self::assertNotContains('organization_id', $filterIds);
        self::assertNotContains('project_id', $filterIds);
        self::assertNotContains('user_id', $filterIds);
        self::assertSame('contract-management', $definition->sourceModule);
        self::assertSame(AcceptedProductionCandidateContract::FORMULA_VERSION, $definition->formulaVersion);
        self::assertSame(AcceptedProductionCandidateContract::SOURCE_SCHEMA_VERSION, $definition->sourceSchemaVersion);
        self::assertSame(['reports.production_progress.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['reports.production_progress.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(
            ['budgeting.wip_forecast.view_sensitive_costs'],
            $definition->permissionPolicy->sensitivePermissions,
        );
        self::assertSame([], $definition->permissionPolicy->auditPermissions);
        self::assertSame(
            ['accepted_amount_minor', 'approved_rate_minor'],
            $definition->outputClassification->sensitiveColumnIds,
        );
        self::assertSame([], $definition->outputClassification->auditColumnIds);
        self::assertFalse($definition->outputClassification->totalsSensitive);
        self::assertFalse($definition->outputClassification->totalsAudit);
        self::assertFalse($definition->outputClassification->provenanceAudit);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertSame(8, $builtin->metadata()->manifestOrdinal);
        self::assertFalse($builtin->scheduling()->supportsSubscriptions);
        self::assertFalse($builtin->scheduling()->reproducibleScheduledSnapshot);
    }

    public function test_runtime_binding_uses_the_immutable_projection_and_signed_drill_down(): void
    {
        $contract = new AcceptedProductionCandidateContract;
        $definition = (new AcceptedProductionBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(AcceptedProductionReportProvider::class))->newInstanceWithoutConstructor();
        $rows = new AcceptedProductionRowQuery;
        $drillDown = new AcceptedProductionDrillDownProvider;
        $readiness = (new ReflectionClass(AcceptedProductionReadinessProbe::class))->newInstanceWithoutConstructor();
        $assembler = new AcceptedProductionCapturingBindingAssembler;
        $registrar = new AcceptedProductionPublishedRuntimeBindingRegistrar(
            new AcceptedProductionPublishedRegistry($definition),
            new AcceptedProductionReportBindingFactory(
                $provider,
                $rows,
                $drillDown,
                $readiness,
                $contract,
            ),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[AcceptedProductionCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($rows, $binding->rowQuery);
        self::assertSame($drillDown, $binding->drillDownProvider);
        self::assertInstanceOf(ReportDrillDownTokenColumns::class, $binding->drillDownProvider);
        self::assertSame(['drill' => 'drill'], $drillDown->drillDownTokenColumns());
        self::assertSame($readiness, $binding->readinessProbe);
    }

    public function test_persisted_row_schema_keeps_every_published_business_column(): void
    {
        $definition = (new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract))
            ->definition()
            ->payload();
        $expected = array_values(array_filter(
            array_column($definition->columns, 'id'),
            static fn (string $column): bool => ! in_array($column, ['row_key', 'drill'], true),
        ));
        $reflection = new ReflectionClass(AcceptedProductionSnapshotMaterializer::class);
        $materializer = $reflection->newInstanceWithoutConstructor();

        self::assertSame(
            $expected,
            array_column($reflection->getMethod('rowSchema')->invoke($materializer), 'id'),
        );
    }

    public function test_standard_visibility_keeps_quantity_totals_and_redacts_money(): void
    {
        $definition = (new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract))
            ->definition()
            ->payload();
        $rowQuery = new AcceptedProductionRowQuery;
        $readerProperty = (new ReflectionClass($rowQuery))->getProperty('reader');
        $reader = $readerProperty->getValue($rowQuery);
        $readerReflection = new ReflectionClass($reader);
        $sensitiveColumns = $readerReflection->getProperty('sensitiveColumns')->getValue($reader);
        sort($sensitiveColumns, SORT_STRING);

        self::assertSame($definition->outputClassification->sensitiveColumnIds, $sensitiveColumns);
        self::assertFalse($definition->outputClassification->totalsSensitive);
        self::assertContains(
            'accepted_amount_minor',
            array_column($definition->sorts, 'id'),
        );

        $redacted = $readerReflection->getMethod('redact')->invoke($reader, [
            'accepted_quantity' => '1.000',
            'accepted_amount_minor' => 125_000,
            'by_currency' => [
                'RUB' => [
                    'accepted_quantity' => '1.000',
                    'accepted_amount_minor' => 125_000,
                    'approved_rate_minor' => 125_000,
                ],
            ],
        ]);

        self::assertSame([
            'accepted_quantity' => '1.000',
            'by_currency' => [
                'RUB' => ['accepted_quantity' => '1.000'],
            ],
        ], $redacted);
    }

    public function test_organization_owner_receives_exact_report_permissions(): void
    {
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4).'/config/RoleDefinitions/lk/organization_owner.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $permissions = $document['module_permissions']['reports'] ?? [];

        self::assertContains('reports.production_progress.view', $permissions);
        self::assertContains('reports.production_progress.export', $permissions);
    }
}

final class AcceptedProductionCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class AcceptedProductionPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === AcceptedProductionCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [AcceptedProductionCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
