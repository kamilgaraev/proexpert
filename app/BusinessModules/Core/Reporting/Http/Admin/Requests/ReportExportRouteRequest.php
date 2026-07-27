<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

class ReportExportRouteRequest extends ReportRouteRequest
{
    protected function routeParameter(): string
    {
        return 'exportId';
    }

    final public function exportId(): string
    {
        return $this->routeId();
    }
}
