<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class CostOverrunWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::COST_OVERRUN;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['cost_overruns' => [], 'message' => 'Cost overrun analysis coming soon'];
    }
}

