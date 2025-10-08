<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Activity;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;

class UserActionsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::USER_ACTIONS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        return ['actions' => [], 'message' => 'User actions tracking coming soon'];
    }
}

