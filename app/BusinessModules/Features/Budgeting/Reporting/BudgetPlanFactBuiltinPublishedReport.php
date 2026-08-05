<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class BudgetPlanFactBuiltinPublishedReport
{
    public const CONTRACT_VERSION = '1.0.0';

    public const RENDERER_VERSION = '1.0.0';

    public const MANIFEST_ORDINAL = 12;

    public function __construct(private BudgetPlanFactCandidateContract $contract) {}

    public function definition(): PublishedReportDefinition
    {
        $this->contract->assertRuntimeMatches();

        $definition = (new ReportDefinitionFactory)->fromManifest($this->document());
        $this->contract->assertDefinition($definition);

        return new PublishedReportDefinition($definition);
    }

    public function metadata(): ReportCatalogMetadata
    {
        return new ReportCatalogMetadata(
            BudgetPlanFactCandidateContract::CODE,
            'reports.catalog.budget_plan_fact',
            ReportCatalogGroup::FINANCE,
            'finance',
            'budget_period_article_currency',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(BudgetPlanFactCandidateContract::CODE, false, false);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            'code' => BudgetPlanFactCandidateContract::CODE,
            'title_key' => 'reports.catalog.budget_plan_fact',
            'catalog_group' => 'finance',
            'category' => 'finance',
            'grain' => 'budget_period_article_currency',
            'wave' => 1,
            'source_module' => 'budgeting',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->contract->filters(),
            'columns' => $this->contract->columns(),
            'sorts' => $this->contract->sorts(),
            'formats' => $this->contract->formats(),
            'versions' => [
                'contract' => self::CONTRACT_VERSION,
                'formula' => $this->contract->formulaVersion,
                'source_schema' => $this->contract->sourceSchemaVersion,
                'renderer' => self::RENDERER_VERSION,
            ],
            'semantic_fingerprints' => [
                'formula' => $this->contract->formulaHash,
                'source' => $this->contract->sourceHash,
            ],
            'permissions' => [
                'view' => ['budgeting.plan_fact.view'],
                'export' => ['budgeting.plan_fact.export'],
                'sensitive' => [],
                'audit' => [],
            ],
            'readiness' => [
                'source' => 'ready',
                'formula' => 'ready',
                'delivery' => 'verified',
                'publication' => 'published',
            ],
            'capabilities' => [
                'supports_subscriptions' => false,
                'reproducible_scheduled_snapshot' => false,
            ],
        ];
    }
}
