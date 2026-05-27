<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Filament\Support\FilamentPermission;
use App\Models\ContactForm;
use App\Models\SystemAdmin;

class SupportRequestPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::SUPPORT_VIEW);
    }

    public function view(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::SUPPORT_VIEW);
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return false;
    }

    public function update(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return false;
    }

    public function delete(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return false;
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return false;
    }

    public function assign(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    public function changeStatus(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    public function addInternalNote(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    public function linkOrganization(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    public function escalate(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    public function viewTechnicalFields(SystemAdmin $systemAdmin, ContactForm $supportRequest): bool
    {
        return $this->manage($systemAdmin);
    }

    private function manage(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, FilamentPermission::SUPPORT_MANAGE);
    }
}
