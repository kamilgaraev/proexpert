<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinPublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\CompositePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabasePublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\DatabaseReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BuiltinPublishedReportDefinitionRegistryTest extends TestCase
{
    public function test_provider_exposes_builtin_through_generic_registry(): void
    {
        $app = new Application(dirname(__DIR__, 4));
        $app->instance(DatabasePublishedReportDefinitionRegistry::class, $this->registry([]));
        $app->instance(DatabaseReportCatalogMetadataRegistry::class, new class implements ReportCatalogMetadataRegistry
        {
            public function published(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
        });
        $app->instance(DatabaseReportSchedulingCapabilityRegistry::class, new class implements ReportSchedulingCapabilityRegistry
        {
            public function published(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability
            {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
        });
        (new ReportingCatalogServiceProvider($app))->register();

        $registry = $app->make(ReportDefinitionRegistry::class);

        self::assertSame('project_margin', $registry->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $registry->published('budget_plan_fact')->code);
        self::assertSame('project_margin', $app->make(ReportCatalogMetadataRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportCatalogMetadataRegistry::class)->published('budget_plan_fact')->code);
        self::assertSame('project_margin', $app->make(ReportSchedulingCapabilityRegistry::class)->published('project_margin')->code);
        self::assertSame('budget_plan_fact', $app->make(ReportSchedulingCapabilityRegistry::class)->published('budget_plan_fact')->code);
    }

    public function test_budget_plan_fact_is_available_without_database_publication(): void
    {
        $builtins = new BuiltinPublishedReportDefinitionRegistry(
            $this->projectMargin(),
            new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract),
        );
        $registry = new CompositePublishedReportDefinitionRegistry($builtins, $this->registry([]));

        self::assertSame(['budget_plan_fact', 'project_margin'], $registry->publishedCodes());
        self::assertSame('project_margin', $registry->published('project_margin')->code);
        $definition = $registry->published('budget_plan_fact');
        $payload = $definition->payload();

        self::assertSame('budget_plan_fact', $definition->code);
        self::assertSame(['budgeting.plan_fact.view'], $payload->permissionPolicy->viewPermissions);
        self::assertSame(['budgeting.plan_fact.export'], $payload->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $payload->formats);
        self::assertSame(
            hash('sha256', CanonicalJson::encode((new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->document())),
            $definition->definitionHash->value,
        );
    }

    public function test_database_published_report_remains_available(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract)),
            $this->registry(['ordinary_report' => $builtin]),
        );

        self::assertSame(['budget_plan_fact', 'project_margin', 'ordinary_report'], $registry->publishedCodes());
        self::assertSame($builtin, $registry->published('ordinary_report'));
    }

    public function test_database_slug_conflicting_with_builtin_is_rejected(): void
    {
        $builtin = (new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract))->definition();
        $registry = new CompositePublishedReportDefinitionRegistry(
            new BuiltinPublishedReportDefinitionRegistry($this->projectMargin(), new BudgetPlanFactBuiltinPublishedReport(new BudgetPlanFactCandidateContract)),
            $this->registry(['budget_plan_fact' => $builtin]),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_published_definition_conflict');

        $registry->published('budget_plan_fact');
    }

    /** @param array<string, PublishedReportDefinition> $definitions */
    private function registry(array $definitions): ReportDefinitionRegistry
    {
        return new class($definitions) implements ReportDefinitionRegistry
        {
            /** @param array<string, PublishedReportDefinition> $definitions */
            public function __construct(private array $definitions) {}

            public function published(string $code): PublishedReportDefinition
            {
                if (! isset($this->definitions[$code])) {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
                }

                return $this->definitions[$code];
            }

            public function publishedCodes(): array
            {
                return array_keys($this->definitions);
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('0', 64));
            }
        };
    }

    private function projectMargin(): ProjectMarginBuiltinPublishedReport
    {
        return new ProjectMarginBuiltinPublishedReport(new ProjectMarginCandidateContract);
    }
}
