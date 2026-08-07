<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSchedulingCapability;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;

final readonly class ManagementPnlBuiltinPublishedReport
{
    public const MANIFEST_ORDINAL = 13;

    public function __construct(private ManagementPnlCandidateContract $contract) {}

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
            ManagementPnlCandidateContract::CODE,
            'reports.catalog.management_pnl',
            ReportCatalogGroup::FINANCE,
            'finance',
            'organization_article_period_scenario',
            1,
            self::MANIFEST_ORDINAL,
        );
    }

    public function scheduling(): ReportSchedulingCapability
    {
        return new ReportSchedulingCapability(ManagementPnlCandidateContract::CODE, false, false);
    }

    public function document(): array
    {
        return $this->contract->document('published');
    }
}
