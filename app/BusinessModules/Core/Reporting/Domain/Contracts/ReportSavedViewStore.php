<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\CreateReportSavedViewData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\UpdateReportSavedViewData;

interface ReportSavedViewStore
{
    public function list(int $organizationId, int $ownerId, ReportSavedViewWindow $window): ReportSavedViewPage;

    public function getVisible(int $organizationId, int $ownerId, string $id): ReportSavedView;

    public function create(int $organizationId, int $ownerId, CreateReportSavedViewData $data, string $contractVersion): ReportSavedView;

    public function updateLocked(int $organizationId, int $ownerId, string $id, UpdateReportSavedViewData $data): ReportSavedView;

    public function setDefaultLocked(int $organizationId, int $ownerId, string $id): ReportSavedView;

    public function softDeleteLocked(int $organizationId, int $ownerId, string $id): void;
}
