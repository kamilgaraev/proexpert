<?php

declare(strict_types=1);

namespace Tests\Unit\Project;

use App\Services\Project\ProjectBudgetAmountService;
use PHPUnit\Framework\TestCase;

final class ProjectBudgetAmountServiceTest extends TestCase
{
    public function test_manual_project_planned_cost_is_marked_as_project_summary_amount(): void
    {
        $service = new ProjectBudgetAmountService;

        $payload = $service->applyProjectPlannedCost([
            'name' => 'Pilot Build',
            'additional_info' => [
                'source' => 'manual_form',
            ],
        ], 1_234_567.89, 'manual');

        self::assertSame(1_234_567.89, $payload['budget_amount']);
        self::assertSame('manual_form', $payload['additional_info']['source']);
        self::assertSame('project_planned_cost', $payload['additional_info']['budget_amount_context']['contour']);
        self::assertSame('manual', $payload['additional_info']['budget_amount_context']['source']);
    }

    public function test_conversion_project_planned_cost_is_not_marked_as_budgeting_lines(): void
    {
        $service = new ProjectBudgetAmountService;

        $payload = $service->applyProjectPlannedCost([], 1_500_000.0, 'crm_conversion');

        self::assertSame(1_500_000.0, $payload['budget_amount']);
        self::assertSame('project_planned_cost', $payload['additional_info']['budget_amount_context']['contour']);
        self::assertSame('crm_conversion', $payload['additional_info']['budget_amount_context']['source']);
        self::assertFalse($payload['additional_info']['budget_amount_context']['creates_budget_lines']);
    }

    public function test_unchanged_project_planned_cost_preserves_existing_context(): void
    {
        $service = new ProjectBudgetAmountService;

        $payload = $service->preserveProjectPlannedCostContext(
            ['additional_info' => ['status_note' => 'updated']],
            [
                'budget_amount_context' => [
                    'contour' => 'project_planned_cost',
                    'source' => 'crm_conversion',
                    'creates_budget_lines' => false,
                ],
            ],
            'manual'
        );

        self::assertSame('updated', $payload['additional_info']['status_note']);
        self::assertSame('project_planned_cost', $payload['additional_info']['budget_amount_context']['contour']);
        self::assertSame('crm_conversion', $payload['additional_info']['budget_amount_context']['source']);
        self::assertFalse($payload['additional_info']['budget_amount_context']['creates_budget_lines']);
    }

    public function test_approved_estimate_does_not_auto_overwrite_project_planned_cost(): void
    {
        $service = new ProjectBudgetAmountService;

        self::assertFalse($service->allowsApprovedEstimateAutoOverwrite());
    }
}
