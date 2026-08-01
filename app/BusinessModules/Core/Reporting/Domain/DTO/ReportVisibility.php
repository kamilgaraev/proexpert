<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportVisibility
{
    public function __construct(
        public bool $canView,
        public bool $canRun,
        public bool $canExport,
        public bool $canDownload,
        public bool $canManage,
        public bool $canViewSensitive,
        public bool $canViewAudit,
    ) {
        if (($canDownload && (!$canExport || !$canView)) || (($canExport || $canRun || $canManage) && !$canView)) {
            throw new InvalidArgumentException('report_visibility_invalid');
        }
    }
}
