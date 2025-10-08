<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class TrendAnalysisWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::TREND_ANALYSIS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['trends' => [], 'message' => 'Trend analysis coming soon'];
    }
}

