<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimeEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'project_id' => $this->project_id,
            'work_type_id' => $this->work_type_id,
            'task_id' => $this->task_id,
            'work_date' => $this->work_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'hours_worked' => $this->hours_worked,
            'break_time' => $this->break_time,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at,
            'rejection_reason' => $this->rejection_reason,
            'is_billable' => $this->is_billable,
            'hourly_rate' => $this->hourly_rate,
            'location' => $this->location,
            'custom_fields' => $this->custom_fields,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Вычисляемые поля
            'total_cost' => $this->total_cost,
            'is_active_timer' => $this->start_time && !$this->end_time && $this->status === 'draft',
            'duration_formatted' => $this->formatDuration(),
            
            // Связанные модели
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'code' => $this->project->code ?? null,
                ];
            }),
            
            'work_type' => $this->whenLoaded('workType', function () {
                return [
                    'id' => $this->workType->id,
                    'name' => $this->workType->name,
                    'category' => $this->workType->category ?? null,
                ];
            }),
            
            'task' => $this->whenLoaded('task', function () {
                return [
                    'id' => $this->task->id,
                    'name' => $this->task->name,
                    'description' => $this->task->description ?? null,
                ];
            }),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                    'email' => $this->approvedBy->email,
                ];
            }),
        ];
    }
    
    /**
     * Форматировать продолжительность в читаемый вид
     */
    private function formatDuration(): ?string
    {
        if (!$this->hours_worked) {
            return null;
        }
        
        $hours = floor($this->hours_worked);
        $minutes = round(($this->hours_worked - $hours) * 60);
        
        if ($hours > 0 && $minutes > 0) {
            return "{$hours}ч {$minutes}м";
        } elseif ($hours > 0) {
            return "{$hours}ч";
        } else {
            return "{$minutes}м";
        }
    }
}