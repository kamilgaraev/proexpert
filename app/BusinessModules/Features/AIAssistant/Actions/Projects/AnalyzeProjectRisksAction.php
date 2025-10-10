<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Projects;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Projects\ProjectsRisksWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\DeadlineRiskWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Predictive\BudgetRiskWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class AnalyzeProjectRisksAction
{
    protected ProjectsRisksWidgetProvider $risksProvider;
    protected DeadlineRiskWidgetProvider $deadlineRiskProvider;
    protected BudgetRiskWidgetProvider $budgetRiskProvider;

    public function __construct(
        ProjectsRisksWidgetProvider $risksProvider,
        DeadlineRiskWidgetProvider $deadlineRiskProvider,
        BudgetRiskWidgetProvider $budgetRiskProvider
    ) {
        $this->risksProvider = $risksProvider;
        $this->deadlineRiskProvider = $deadlineRiskProvider;
        $this->budgetRiskProvider = $budgetRiskProvider;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $risksRequest = new WidgetDataRequest(
            widgetType: WidgetType::PROJECTS_RISKS,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $deadlineRequest = new WidgetDataRequest(
            widgetType: WidgetType::DEADLINE_RISK,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $budgetRequest = new WidgetDataRequest(
            widgetType: WidgetType::BUDGET_RISK,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $risks = $this->risksProvider->getData($risksRequest);
        $deadlineRisks = $this->deadlineRiskProvider->getData($deadlineRequest);
        $budgetRisks = $this->budgetRiskProvider->getData($budgetRequest);

        return [
            'projects_at_risk' => $risks->data['at_risk_count'] ?? 0,
            'deadline_risks' => $deadlineRisks->data['projects'] ?? [],
            'budget_risks' => $budgetRisks->data['projects'] ?? [],
            'total_risks' => ($risks->data['at_risk_count'] ?? 0),
        ];
    }
}

