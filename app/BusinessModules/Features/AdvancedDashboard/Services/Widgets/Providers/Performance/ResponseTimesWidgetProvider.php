<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class ResponseTimesWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RESPONSE_TIMES;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return [
            'response_times' => [],
            'message' => 'Response times require APM tool integration (e.g., New Relic, Datadog)',
        ];
    }
}

