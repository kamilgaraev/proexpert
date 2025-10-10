<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsStatusWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class GetProjectStatusAction
{
    protected ProjectsStatusWidgetProvider $widgetProvider;

    public function __construct(ProjectsStatusWidgetProvider $widgetProvider)
    {
        $this->widgetProvider = $widgetProvider;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $request = new WidgetDataRequest(
            widgetType: WidgetType::PROJECTS_STATUS,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $data = $this->widgetProvider->getData($request);

        return [
            'total_projects' => $data->data['total'] ?? 0,
            'active' => $data->data['active'] ?? 0,
            'completed' => $data->data['completed'] ?? 0,
            'on_hold' => $data->data['on_hold'] ?? 0,
            'archived' => $data->data['archived'] ?? 0,
        ];
    }
}

