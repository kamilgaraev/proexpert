<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class ReportCatalogDefinitionView
{
    public function __construct(
        public string $code,
        public string $titleKey,
        public ReportCatalogGroup $catalogGroup,
        public string $category,
        public string $grain,
        public int $wave,
        public Sha256Hash $definitionHash,
        public string $contractVersion,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $rendererVersion,
        public string $sourceModule,
        public ReportCoreAccessMode $coreAccessMode,
        public ReportPermissionPolicy $permissionPolicy,
        public array $filters,
        public array $columns,
        public array $sorts,
        public array $formats,
        public ReportSchedulingCapability $scheduling,
        public ReportVisibility $visibility,
    ) {}

    public static function from(
        PublishedReportDefinition $published,
        ReportCatalogMetadata $metadata,
        ReportSchedulingCapability $scheduling,
        ReportVisibility $visibility,
    ): self {
        $definition = $published->payload();

        return new self(
            $definition->code,
            $metadata->titleKey,
            $metadata->catalogGroup,
            $metadata->category,
            $metadata->grain,
            $metadata->wave,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
            $definition->sourceModule,
            $definition->coreAccessMode,
            $definition->permissionPolicy,
            $definition->filters,
            $definition->columns,
            $definition->sorts,
            $definition->formats,
            $scheduling,
            $visibility,
        );
    }
}
