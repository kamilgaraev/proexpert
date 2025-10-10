<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsBudgetWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class GetProjectBudgetAction
{
    protected ProjectsBudgetWidgetProvider $widgetProvider;

    public function __construct(ProjectsBudgetWidgetProvider $widgetProvider)
    {
        $this->widgetProvider = $widgetProvider;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $request = new WidgetDataRequest(
            widgetType: WidgetType::PROJECTS_BUDGET,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $data = $this->widgetProvider->getData($request);

        return [
            'total_budget' => $data->data['total_budget'] ?? 0,
            'spent' => $data->data['spent'] ?? 0,
            'remaining' => $data->data['remaining'] ?? 0,
            'percentage_used' => $data->data['percentage_used'] ?? 0,
        ];
    }
}

