<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\SystemAdmin;

class NotificationPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.delivery_log.view');
    }

    public function view(SystemAdmin $systemAdmin, Notification $notification): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.delivery_log.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return false;
    }

    public function update(SystemAdmin $systemAdmin, Notification $notification): bool
    {
        return false;
    }

    public function delete(SystemAdmin $systemAdmin, Notification $notification): bool
    {
        return false;
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return false;
    }
}
