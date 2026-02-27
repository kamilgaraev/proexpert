<?php

namespace App\Http\Resources\Api\V1\Admin\CompletedWork;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource;
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;

class CompletedWorkResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                 => $this->id,
            'organization_id'    => $this->organization_id,
            'project'            => new ProjectMiniResource($this->whenLoaded('project')),
            'contract'           => new ContractMiniResource($this->whenLoaded('contract')),
            'work_type'          => new WorkTypeResource($this->whenLoaded('workType')),
            'user'               => new UserResource($this->whenLoaded('user')),
            'contractor'         => new \App\Http\Resources\Api\V1\Admin\Contractor\ContractorMiniResource($this->whenLoaded('contractor')),
            'contractor_id'      => $this->contractor_id,
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
            'quantity'           => (float)$this->quantity,
            'completed_quantity' => $this->completed_quantity !== null ? (float)$this->completed_quantity : null,
            'price'              => isset($this->price) ? (float)$this->price : null,
            'total_amount'       => isset($this->total_amount) ? (float)$this->total_amount : null,
            'completion_date'    => $this->completion_date->format('Y-m-d'),
            'notes'              => $this->notes,
            'status'             => $this->status,
            'additional_info'    => $this->additional_info,
            'materials'          => \App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkMaterialResource::collection($this->whenLoaded('materials')),
            'created_at'         => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at'         => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 