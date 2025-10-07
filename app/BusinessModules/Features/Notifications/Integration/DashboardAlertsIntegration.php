<?php

namespace App\BusinessModules\Features\Notifications\Integration;

use App\BusinessModules\Features\AdvancedDashboard\Models\DashboardAlert;
use App\BusinessModules\Features\Notifications\Facades\Notify;

class DashboardAlertsIntegration
{
    public static function sendAlert(DashboardAlert $alert): void
    {
        $user = $alert->user;

        if (!$user) {
            return;
        }

        Notify::send(
            $user,
            'dashboard_alert',
            [
                'alert' => [
                    'id' => $alert->id,
                    'name' => $alert->name,
                    'description' => $alert->description,
                    'alert_type' => $alert->alert_type,
                    'target_entity' => $alert->target_entity,
                    'threshold_value' => $alert->threshold_value,
                    'threshold_unit' => $alert->threshold_unit,
                    'priority' => $alert->priority,
                    'triggered_at' => now()->format('d.m.Y H:i'),
                    'url' => url("/dashboards/{$alert->dashboard_id}"),
                ],
            ],
            'system',
            $alert->priority,
            null,
            $alert->organization_id
        );
    }
}

