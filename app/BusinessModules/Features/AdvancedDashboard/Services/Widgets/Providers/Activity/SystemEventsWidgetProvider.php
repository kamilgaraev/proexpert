<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class SystemEventsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::SYSTEM_EVENTS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['events' => [], 'message' => 'System events tracking coming soon'];
    }
}

