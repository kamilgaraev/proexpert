<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Enums\ProjectOrganizationRole;

class ProjectOrganizationRoleChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Project $project,
        public Organization $organization,
        public ProjectOrganizationRole $oldRole,
        public ProjectOrganizationRole $newRole,
        public ?User $changedBy = null
    ) {}
}
