<?php

declare(strict_types=1);

namespace App\Enums;

enum UserProjectAccessMode: string
{
    case ALL_PROJECTS = 'all_projects';
    case ASSIGNED_PROJECTS = 'assigned_projects';

    public function label(): string
    {
        return trans_message('user_project_access.modes.' . $this->value);
    }
}
