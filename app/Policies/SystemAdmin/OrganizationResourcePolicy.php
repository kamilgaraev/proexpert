<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Organization;
use App\Models\SystemAdmin;

class OrganizationResourcePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.view');
    }

    public function view(SystemAdmin $systemAdmin, Organization $organization): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.create');
    }

    public function update(SystemAdmin $systemAdmin, Organization $organization): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.update');
    }

    public function delete(SystemAdmin $systemAdmin, Organization $organization): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.delete');
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.organizations.delete');
    }
}
