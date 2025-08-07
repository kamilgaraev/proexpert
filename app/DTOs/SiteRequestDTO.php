<?php

namespace App\DTOs;

use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use App\Enums\SiteRequest\PersonnelTypeEnum;

class SiteRequestDTO
{
    public function __construct(
        public int $organization_id,
        public int $project_id,
        public int $user_id,
        public string $title,
        public string $description,
        public SiteRequestStatusEnum $status,
        public SiteRequestPriorityEnum $priority,
        public SiteRequestTypeEnum $request_type,
        public ?string $required_date = null,
        public ?string $notes = null,
        public array $files = [],
        // Поля для заявок на персонал
        public ?PersonnelTypeEnum $personnel_type = null,
        public ?int $personnel_count = null,
        public ?string $personnel_requirements = null,
        public ?float $hourly_rate = null,
        public ?int $work_hours_per_day = null,
        public ?string $work_start_date = null,
        public ?string $work_end_date = null,
        public ?string $work_location = null,
        public ?string $additional_conditions = null,
    ) {}

    public function toArrayForCreate(): array
    {
        $data = [
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'request_type' => $this->request_type->value,
            'required_date' => $this->required_date,
            'notes' => $this->notes,
        ];

        // Добавляем поля для заявок на персонал, если они заданы
        if ($this->personnel_type !== null) {
            $data['personnel_type'] = $this->personnel_type->value;
        }
        if ($this->personnel_count !== null) {
            $data['personnel_count'] = $this->personnel_count;
        }
        if ($this->personnel_requirements !== null) {
            $data['personnel_requirements'] = $this->personnel_requirements;
        }
        if ($this->hourly_rate !== null) {
            $data['hourly_rate'] = $this->hourly_rate;
        }
        if ($this->work_hours_per_day !== null) {
            $data['work_hours_per_day'] = $this->work_hours_per_day;
        }
        if ($this->work_start_date !== null) {
            $data['work_start_date'] = $this->work_start_date;
        }
        if ($this->work_end_date !== null) {
            $data['work_end_date'] = $this->work_end_date;
        }
        if ($this->work_location !== null) {
            $data['work_location'] = $this->work_location;
        }
        if ($this->additional_conditions !== null) {
            $data['additional_conditions'] = $this->additional_conditions;
        }

        return $data;
    }
}