<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use App\Models\SystemAdmin;

class NotificationAnalyticsPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.analytics.view');
    }

    public function view(SystemAdmin $systemAdmin, NotificationAnalytics $notificationAnalytics): bool
    {
        return $this->allows($systemAdmin, 'system_admin.notifications.analytics.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return false;
    }

    public function update(SystemAdmin $systemAdmin, NotificationAnalytics $notificationAnalytics): bool
    {
        return false;
    }

    public function delete(SystemAdmin $systemAdmin, NotificationAnalytics $notificationAnalytics): bool
    {
        return false;
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return false;
    }
}
