<?php

namespace App\Http\Resources\Api\V1\Admin\CompletedWork;

use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorMiniResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\ScheduleTask;
use App\Models\WorkType;
use Illuminate\Http\Resources\Json\JsonResource;

class CompletedWorkResource extends JsonResource
{
    public function toArray($request)
    {
        $scheduleTask = $this->scheduleTask;
        $shouldSyncFromTask = $this->shouldSyncFromTask($scheduleTask);
        $contract = $this->resolveContract($scheduleTask);
        $contractor = $this->resolveContractor($contract, $scheduleTask);
        $workType = $this->resolveWorkType($scheduleTask);
        $completedQuantity = $this->resolveCompletedQuantity($scheduleTask, $shouldSyncFromTask);
        $price = $this->resolvePrice($scheduleTask);
        $totalAmount = $this->resolveTotalAmount($shouldSyncFromTask, $completedQuantity, $price);

        return [
            'id'                 => $this->id,
            'organization_id'    => $this->organization_id,
            'project_id'         => $this->project_id,
            'contract_id'        => $contract?->id ?? $this->contract_id,
            'work_type_id'       => $workType?->id ?? $this->work_type_id,
            'user_id'            => $this->user_id,
            'project'            => new ProjectMiniResource($this->whenLoaded('project')),
            'contract'           => $contract ? new ContractMiniResource($contract) : null,
            'work_type'          => $workType ? new WorkTypeResource($workType) : null,
            'user'               => new UserResource($this->whenLoaded('user')),
            'contractor'         => $contractor ? new ContractorMiniResource($contractor) : null,
            'contractor_id'      => $contractor?->id ?? $this->contractor_id,
            'schedule_task_id'   => $this->schedule_task_id,
            'schedule_task'      => $this->whenLoaded('scheduleTask', fn() => [
                'id'                 => $this->scheduleTask->id,
                'name'               => $this->scheduleTask->name,
                'wbs_code'           => $this->scheduleTask->wbs_code,
                'quantity'           => $this->scheduleTask->quantity ? (float)$this->scheduleTask->quantity : null,
                'completed_quantity' => $this->scheduleTask->completed_quantity ? (float)$this->scheduleTask->completed_quantity : null,
                'progress_percent'   => $this->scheduleTask->progress_percent ? (float)$this->scheduleTask->progress_percent : null,
                'planned_start_date' => $this->scheduleTask->planned_start_date?->format('Y-m-d'),
                'planned_end_date'   => $this->scheduleTask->planned_end_date?->format('Y-m-d'),
                'measurement_unit'   => $this->scheduleTask->measurementUnit ? [
                    'id'         => $this->scheduleTask->measurementUnit->id,
                    'short_name' => $this->scheduleTask->measurementUnit->short_name,
                ] : null,
                'procurement_status' => $this->scheduleTask->estimateItem?->procurement_status,
            ]),
            'quantity'           => $this->quantity !== null ? (float) $this->quantity : null,
            'completed_quantity' => $completedQuantity,
            'price'              => $price,
            'total_amount'       => $totalAmount,
            'completion_date'    => $this->completion_date->format('Y-m-d'),
            'notes'              => $this->notes,
            'status'             => $this->status,
            'additional_info'    => $this->additional_info,
            'materials'          => \App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkMaterialResource::collection($this->whenLoaded('materials')),
            'created_at'         => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at'         => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveContract(?ScheduleTask $scheduleTask): ?Contract
    {
        if ($this->contract) {
            return $this->contract;
        }

        return $this->resolveContractLink($scheduleTask)?->contract;
    }

    private function resolveContractor(?Contract $contract, ?ScheduleTask $scheduleTask): ?Contractor
    {
        if ($this->contractor) {
            return $this->contractor;
        }

        if ($contract?->contractor) {
            return $contract->contractor;
        }

        return $this->resolveContractLink($scheduleTask)?->contract?->contractor;
    }

    private function resolveWorkType(?ScheduleTask $scheduleTask): ?WorkType
    {
        if ($this->workType) {
            return $this->workType;
        }

        if ($scheduleTask?->workType) {
            return $scheduleTask->workType;
        }

        return $scheduleTask?->estimateItem?->workType;
    }

    private function resolveCompletedQuantity(?ScheduleTask $scheduleTask, bool $shouldSyncFromTask): ?float
    {
        if ($shouldSyncFromTask) {
            return $this->resolveTaskCompletedQuantity($scheduleTask);
        }

        if ($this->completed_quantity !== null) {
            return (float) $this->completed_quantity;
        }

        return $this->resolveTaskCompletedQuantity($scheduleTask)
            ?? ($this->quantity !== null ? (float) $this->quantity : null);
    }

    private function resolvePrice(?ScheduleTask $scheduleTask): ?float
    {
        if ($this->price !== null && (float) $this->price > 0) {
            return (float) $this->price;
        }

        $contractLink = $this->resolveContractLink($scheduleTask);
        $linkedQuantity = (float) ($contractLink?->quantity ?? 0);
        $linkedAmount = $contractLink?->amount !== null ? (float) $contractLink->amount : null;

        if ($linkedAmount !== null && $linkedQuantity > 0) {
            return round($linkedAmount / $linkedQuantity, 2);
        }

        $estimateItem = $scheduleTask?->estimateItem;
        if (!$estimateItem) {
            $baseQuantity = $this->resolveStoredAmountQuantity();

            return $this->total_amount !== null && $baseQuantity > 0
                ? round((float) $this->total_amount / $baseQuantity, 2)
                : null;
        }

        foreach (['actual_unit_price', 'current_unit_price', 'unit_price'] as $field) {
            if ($estimateItem->{$field} !== null && (float) $estimateItem->{$field} > 0) {
                return round((float) $estimateItem->{$field}, 2);
            }
        }

        $baseQuantity = $this->resolveStoredAmountQuantity();

        return $this->total_amount !== null && $baseQuantity > 0
            ? round((float) $this->total_amount / $baseQuantity, 2)
            : null;
    }

    private function resolveTotalAmount(bool $shouldSyncFromTask, ?float $completedQuantity, ?float $price): ?float
    {
        if ($shouldSyncFromTask && $price !== null && $completedQuantity !== null) {
            return round($price * $completedQuantity, 2);
        }

        if ($this->total_amount !== null && (float) $this->total_amount > 0) {
            return (float) $this->total_amount;
        }

        if ($price !== null && $completedQuantity !== null) {
            return round($price * $completedQuantity, 2);
        }

        return null;
    }

    private function resolveTaskCompletedQuantity(?ScheduleTask $scheduleTask): ?float
    {
        if (!$scheduleTask) {
            return null;
        }

        if ($scheduleTask->completed_quantity !== null && (float) $scheduleTask->completed_quantity > 0) {
            return (float) $scheduleTask->completed_quantity;
        }

        if ($scheduleTask->quantity !== null && (float) $scheduleTask->quantity > 0 && $scheduleTask->progress_percent !== null) {
            return round((float) $scheduleTask->quantity * ((float) $scheduleTask->progress_percent / 100), 4);
        }

        return null;
    }

    private function resolveContractLink(?ScheduleTask $scheduleTask): ?ContractEstimateItem
    {
        return $scheduleTask?->estimateItem?->contractLinks
            ?->sortBy('id')
            ->first();
    }

    private function shouldSyncFromTask(?ScheduleTask $scheduleTask): bool
    {
        if (!$scheduleTask || !in_array($this->status, ['draft', 'pending', 'in_review'], true)) {
            return false;
        }

        $taskCompletedQuantity = $this->resolveTaskCompletedQuantity($scheduleTask);
        if ($taskCompletedQuantity === null) {
            return false;
        }

        return $this->completed_quantity === null || abs((float) $this->completed_quantity - $taskCompletedQuantity) > 0.0001;
    }

    private function resolveStoredAmountQuantity(): float
    {
        if ($this->completed_quantity !== null && (float) $this->completed_quantity > 0) {
            return (float) $this->completed_quantity;
        }

        return (float) ($this->quantity ?? 0);
    }
}
