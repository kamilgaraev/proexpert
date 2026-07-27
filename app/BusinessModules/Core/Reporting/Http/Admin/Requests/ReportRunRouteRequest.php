<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

class ReportRunRouteRequest extends ReportRouteRequest
{
    protected function routeParameter(): string
    {
        return 'runId';
    }

    final public function runId(): string
    {
        return $this->routeId();
    }
}
