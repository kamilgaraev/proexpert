<?php

namespace App\DTOs\SiteRequest;

use Carbon\Carbon;
use App\Enums\SiteRequest\SiteRequestStatusEnum;
use App\Enums\SiteRequest\SiteRequestPriorityEnum;
use App\Enums\SiteRequest\SiteRequestTypeEnum;
use Illuminate\Http\UploadedFile;

class SiteRequestDTO
{
    /**
     * @param int|null $id
     * @param int $organization_id
     * @param int $project_id
     * @param int $user_id ID пользователя (прораба)
     * @param string $title
     * @param string|null $description
     * @param SiteRequestStatusEnum|string $status
     * @param SiteRequestPriorityEnum|string $priority
     * @param SiteRequestTypeEnum|string $request_type
     * @param Carbon|string|null $required_date
     * @param string|null $notes
     * @param UploadedFile[]|null $files Массив загружаемых файлов (для создания/обновления)
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $organization_id,
        public readonly int $project_id,
        public readonly int $user_id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly SiteRequestStatusEnum|string $status,
        public readonly SiteRequestPriorityEnum|string $priority,
        public readonly SiteRequestTypeEnum|string $request_type,
        public readonly Carbon|string|null $required_date,
        public readonly ?string $notes,
        public readonly ?array $files = null
    ) {}

    /**
     * Преобразует DTO в массив для создания записи в БД.
     */
    public function toArrayForCreate(): array
    {
        $data = [
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status instanceof SiteRequestStatusEnum ? $this->status->value : (string) $this->status,
            'priority' => $this->priority instanceof SiteRequestPriorityEnum ? $this->priority->value : (string) $this->priority,
            'request_type' => $this->request_type instanceof SiteRequestTypeEnum ? $this->request_type->value : (string) $this->request_type,
            'notes' => $this->notes,
        ];
        if ($this->required_date) {
            $data['required_date'] = $this->required_date instanceof Carbon ? $this->required_date->toDateString() : Carbon::parse((string)$this->required_date)->toDateString();
        }
        return $data;
    }

    /**
     * Преобразует DTO в массив для обновления записи в БД.
     * Включает только те поля, которые потенциально могли измениться.
     * User_id и organization_id обычно не меняются при обновлении.
     */
    public function toArrayForUpdate(): array
    {
        $data = [];
        // Эти поля обычно устанавливаются и могут быть изменены
        $data['title'] = $this->title;
        $data['description'] = $this->description; // Может быть null
        $data['status'] = $this->status instanceof SiteRequestStatusEnum ? $this->status->value : (string) $this->status;
        $data['priority'] = $this->priority instanceof SiteRequestPriorityEnum ? $this->priority->value : (string) $this->priority;
        $data['request_type'] = $this->request_type instanceof SiteRequestTypeEnum ? $this->request_type->value : (string) $this->request_type;
        $data['notes'] = $this->notes; // Может быть null
        
        if ($this->required_date) {
             $data['required_date'] = $this->required_date instanceof Carbon ? $this->required_date->toDateString() : Carbon::parse((string)$this->required_date)->toDateString();
        } else {
            // Позволяем установить required_date в null, если он передан как null
             $data['required_date'] = null;
        }

        // project_id может меняться, если это разрешено бизнес-логикой
        $data['project_id'] = $this->project_id;

        return $data;
    }
} 