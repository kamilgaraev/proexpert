<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\SystemAdmin;

class SystemAdminResourcePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.admins.view');
    }

    public function view(SystemAdmin $systemAdmin, SystemAdmin $record): bool
    {
        return $this->allows($systemAdmin, 'system_admin.admins.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.admins.create');
    }

    public function update(SystemAdmin $systemAdmin, SystemAdmin $record): bool
    {
        if ($systemAdmin->id === $record->id) {
            return $this->allows($systemAdmin, 'system_admin.admins.update');
        }

        return $this->allows($systemAdmin, 'system_admin.admins.update')
            && $systemAdmin->canManageRoleSlug($record->getRoleSlug());
    }

    public function delete(SystemAdmin $systemAdmin, SystemAdmin $record): bool
    {
        if ($systemAdmin->id === $record->id) {
            return false;
        }

        return $this->allows($systemAdmin, 'system_admin.admins.delete')
            && $systemAdmin->canManageRoleSlug($record->getRoleSlug());
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.admins.delete');
    }
}
