<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Resources;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborPayrollAccrual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionLaborPayrollAccrual */
final class ProductionLaborPayrollAccrualResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionLaborPayrollAccrual $accrual */
        $accrual = $this->resource;

        return [
            'id' => $accrual->id,
            'work_order_id' => $accrual->work_order_id,
            'work_order_line_id' => $accrual->work_order_line_id,
            'project_id' => $accrual->project_id,
            'schedule_task_id' => $accrual->schedule_task_id,
            'period_start' => $accrual->period_start?->toDateString(),
            'period_end' => $accrual->period_end?->toDateString(),
            'accepted_quantity' => (float) $accrual->accepted_quantity,
            'accepted_hours' => (float) $accrual->accepted_hours,
            'amount' => (float) $accrual->amount,
            'status' => $accrual->status,
            'status_label' => trans_message("production_labor.payroll_statuses.{$accrual->status}"),
            'payment_payload' => $accrual->payment_payload,
            'workflow_summary' => [
                'stage' => $accrual->status,
                'status' => $accrual->status,
                'stage_label' => trans_message("production_labor.payroll_statuses.{$accrual->status}"),
                'available_actions' => [],
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => [],
            'line' => $this->whenLoaded('line', fn () => $accrual->line ? [
                'id' => $accrual->line->id,
                'name' => $accrual->line->name,
                'unit' => $accrual->line->unit,
            ] : null),
            'created_at' => $accrual->created_at?->toIso8601String(),
        ];
    }
}
