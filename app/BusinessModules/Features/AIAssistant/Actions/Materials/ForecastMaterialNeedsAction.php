<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsForecastWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\ResourceDemandWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class ForecastMaterialNeedsAction
{
    protected MaterialsForecastWidgetProvider $forecastProvider;
    protected ResourceDemandWidgetProvider $demandProvider;

    public function __construct(
        MaterialsForecastWidgetProvider $forecastProvider,
        ResourceDemandWidgetProvider $demandProvider
    ) {
        $this->forecastProvider = $forecastProvider;
        $this->demandProvider = $demandProvider;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $forecastRequest = new WidgetDataRequest(
            widgetType: WidgetType::MATERIALS_FORECAST,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $demandRequest = new WidgetDataRequest(
            widgetType: WidgetType::RESOURCE_DEMAND,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $forecast = $this->forecastProvider->getData($forecastRequest);
        $demand = $this->demandProvider->getData($demandRequest);

        return [
            'forecast' => $forecast->data['forecast'] ?? [],
            'demand' => $demand->data['demand'] ?? [],
            'recommendations' => $forecast->data['recommendations'] ?? [],
        ];
    }
}

