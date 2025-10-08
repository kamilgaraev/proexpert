<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class ApiPerformanceWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::API_PERFORMANCE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return [
            'api_metrics' => [],
            'message' => 'API performance metrics coming soon. Integrate with monitoring tools.',
        ];
    }
}

