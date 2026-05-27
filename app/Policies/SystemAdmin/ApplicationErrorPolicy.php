<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Filament\Support\FilamentPermission;
use App\Models\ApplicationError;
use App\Models\SystemAdmin;

class ApplicationErrorPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::MONITORING_VIEW);
    }

    public function view(SystemAdmin $systemAdmin, ApplicationError $applicationError): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::MONITORING_VIEW);
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return false;
    }

    public function update(SystemAdmin $systemAdmin, ApplicationError $applicationError): bool
    {
        return false;
    }

    public function delete(SystemAdmin $systemAdmin, ApplicationError $applicationError): bool
    {
        return false;
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return false;
    }
}
