<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\BusinessModules\Features\AdvancedDashboard\Models\DashboardAlert;

/**
 * Событие срабатывания алерта
 */
class AlertTriggered
{
    use Dispatchable, SerializesModels;

    public DashboardAlert $alert;

    public function __construct(DashboardAlert $alert)
    {
        $this->alert = $alert;
    }
}

