<?php

namespace App\Http\Resources\Api\V1\Admin\SiteRequest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Resources\Api\V1\FileResource;

class SiteRequestResource extends JsonResource
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
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'request_type' => $this->request_type,
            'status' => $this->status,
            'priority' => $this->priority,
            'required_date' => $this->required_date,
            'notes' => $this->notes,
            
            // Поля для заявок на персонал
            'personnel_type' => $this->personnel_type,
            'personnel_count' => $this->personnel_count,
            'personnel_requirements' => $this->personnel_requirements,
            'hourly_rate' => $this->hourly_rate,
            'work_hours_per_day' => $this->work_hours_per_day,
            'work_start_date' => $this->work_start_date,
            'work_end_date' => $this->work_end_date,
            'work_location' => $this->work_location,
            'additional_conditions' => $this->additional_conditions,
            
            // Временные метки
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Связанные данные
            'project' => new ProjectResource($this->whenLoaded('project')),
            'user' => new UserResource($this->whenLoaded('user')),
            'files' => FileResource::collection($this->whenLoaded('files')),
            
            // Дополнительные вычисляемые поля
            'is_personnel_request' => $this->request_type === 'personnel_request',
            'status_label' => $this->getStatusLabel(),
            'priority_label' => $this->getPriorityLabel(),
            'personnel_type_label' => $this->getPersonnelTypeLabel(),
            'days_until_required' => $this->getDaysUntilRequired(),
            'is_overdue' => $this->isOverdue(),
        ];
    }
    
    /**
     * Получить человекочитаемый статус
     */
    private function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Ожидает',
            'in_progress' => 'В работе',
            'completed' => 'Завершена',
            'cancelled' => 'Отменена',
            'on_hold' => 'Приостановлена',
            default => 'Неизвестно'
        };
    }
    
    /**
     * Получить человекочитаемый приоритет
     */
    private function getPriorityLabel(): string
    {
        return match($this->priority) {
            'low' => 'Низкий',
            'medium' => 'Средний',
            'high' => 'Высокий',
            'urgent' => 'Срочный',
            default => 'Неизвестно'
        };
    }
    
    /**
     * Получить человекочитаемый тип персонала
     */
    private function getPersonnelTypeLabel(): ?string
    {
        if (!$this->personnel_type) {
            return null;
        }
        
        return match($this->personnel_type) {
            'foreman' => 'Прораб',
            'worker' => 'Рабочий',
            'engineer' => 'Инженер',
            'technician' => 'Техник',
            'operator' => 'Оператор',
            'driver' => 'Водитель',
            'security' => 'Охранник',
            'cleaner' => 'Уборщик',
            'electrician' => 'Электрик',
            'plumber' => 'Сантехник',
            'welder' => 'Сварщик',
            'carpenter' => 'Плотник',
            'painter' => 'Маляр',
            'mason' => 'Каменщик',
            'roofer' => 'Кровельщик',
            'other' => 'Другое',
            default => 'Неизвестно'
        };
    }
    
    /**
     * Получить количество дней до требуемой даты
     */
    private function getDaysUntilRequired(): int
    {
        if (!$this->required_date) {
            return 0;
        }
        
        return now()->diffInDays($this->required_date, false);
    }
    
    /**
     * Проверить, просрочена ли заявка
     */
    private function isOverdue(): bool
    {
        if (!$this->required_date || in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }
        
        return now()->isAfter($this->required_date);
    }
}