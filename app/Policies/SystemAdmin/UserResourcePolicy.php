<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\SystemAdmin;
use App\Models\User;

class UserResourcePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.view');
    }

    public function view(SystemAdmin $systemAdmin, User $user): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.create');
    }

    public function update(SystemAdmin $systemAdmin, User $user): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.update');
    }

    public function delete(SystemAdmin $systemAdmin, User $user): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.delete');
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.users.delete');
    }
}
