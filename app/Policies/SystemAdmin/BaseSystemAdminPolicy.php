<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\SystemAdmin;

abstract class BaseSystemAdminPolicy
{
    protected function allows(SystemAdmin $systemAdmin, string $permission): bool
    {
        return $systemAdmin->hasSystemPermission($permission);
    }
}
