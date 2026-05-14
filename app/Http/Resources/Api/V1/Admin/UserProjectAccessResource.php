<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\UserProjectAccessMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProjectAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mode = UserProjectAccessMode::tryFrom((string) $this->resource['project_access_mode'])
            ?? UserProjectAccessMode::ASSIGNED_PROJECTS;

        return [
            'user' => [
                'id' => $this->resource['user']->id,
                'name' => $this->resource['user']->name,
                'email' => $this->resource['user']->email,
            ],
            'project_access_mode' => $mode->value,
            'project_access_mode_label' => $mode->label(),
            'project_ids' => $this->resource['project_ids'],
            'project_access' => $this->resource['project_access'],
            'available_projects' => $this->resource['available_projects'],
            'has_full_project_access' => $mode === UserProjectAccessMode::ALL_PROJECTS,
        ];
    }
}
