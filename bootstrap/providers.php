<?php

return [
    App\BusinessModules\Core\Payments\PaymentsServiceProvider::class,
    App\BusinessModules\Features\AdvancedDashboard\AdvancedDashboardServiceProvider::class,
    App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider::class,
    App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesServiceProvider::class,
    App\BusinessModules\Features\Notifications\NotificationServiceProvider::class,
    App\BusinessModules\Features\ScheduleManagement\ScheduleManagementServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
];
