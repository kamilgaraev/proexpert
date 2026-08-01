<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionPresentation;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class ReportSavedViewVersionHasher
{
    public function __construct(private ReportDefinitionRegistry $definitions) {}

    public function hash(
        string $savedViewId,
        int $organizationId,
        int $ownerId,
        int $revision,
        string $reportCode,
        ReportSavedViewVersionPresentation $presentation,
    ): CreateReportSavedViewVersionData {
        $definition = $this->definitions->published($reportCode);
        $content = ReportSavedViewVersionContent::fromPublishedDefinition($definition, $presentation);

        return new CreateReportSavedViewVersionData(
            $savedViewId,
            $organizationId,
            $ownerId,
            $revision,
            $content,
            new Sha256Hash(hash('sha256', $content->canonicalBytes())),
            $definition->definitionHash,
        );
    }
}
