<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class CashFlowForecastWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::CASH_FLOW_FORECAST;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['forecast' => [], 'message' => 'Cash flow forecast coming soon'];
    }
}

