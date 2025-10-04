<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard;

class DashboardUpdated
{
    use Dispatchable, SerializesModels;

    public Dashboard $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }
}

