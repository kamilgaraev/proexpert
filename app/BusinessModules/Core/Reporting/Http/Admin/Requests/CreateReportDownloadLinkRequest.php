<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportDownloadLinkData;

final class CreateReportDownloadLinkRequest extends ReportExportRouteRequest
{
    public function toData(): CreateReportDownloadLinkData
    {
        return new CreateReportDownloadLinkData($this->exportId(), 300);
    }
}
