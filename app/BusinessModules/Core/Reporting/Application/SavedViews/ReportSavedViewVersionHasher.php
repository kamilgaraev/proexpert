<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewVersionData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewVersionContent;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final class ReportSavedViewVersionHasher
{
    public function hash(
        string $savedViewId,
        int $organizationId,
        int $ownerId,
        int $revision,
        ReportSavedViewVersionContent $content,
        Sha256Hash $reportDefinitionHash,
    ): CreateReportSavedViewVersionData {
        return new CreateReportSavedViewVersionData(
            $savedViewId,
            $organizationId,
            $ownerId,
            $revision,
            $content,
            new Sha256Hash(hash('sha256', $content->canonicalBytes())),
            $reportDefinitionHash,
        );
    }
}
