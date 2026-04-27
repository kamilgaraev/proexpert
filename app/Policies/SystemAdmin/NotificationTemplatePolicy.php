<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\Models\SystemAdmin;

class NotificationTemplatePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.view');
    }

    public function view(SystemAdmin $systemAdmin, NotificationTemplate $notificationTemplate): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.create');
    }

    public function update(SystemAdmin $systemAdmin, NotificationTemplate $notificationTemplate): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.update');
    }

    public function delete(SystemAdmin $systemAdmin, NotificationTemplate $notificationTemplate): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.delete');
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.templates.delete');
    }
}
