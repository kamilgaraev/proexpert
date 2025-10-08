<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class AuditLogWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::AUDIT_LOG;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['audit_log' => [], 'message' => 'Audit log tracking coming soon'];
    }
}

