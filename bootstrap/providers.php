<?php

return [
    App\BusinessModules\Addons\AIEstimates\AIEstimatesServiceProvider::class,
    App\BusinessModules\Core\Payments\PaymentsServiceProvider::class,
    App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider::class,
    App\BusinessModules\Features\AdvancedDashboard\AdvancedDashboardServiceProvider::class,
    App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesServiceProvider::class,
    App\BusinessModules\Features\NormativeReferences\NormativeReferencesServiceProvider::class,
    App\BusinessModules\Features\Notifications\NotificationServiceProvider::class,
    App\BusinessModules\Features\Procurement\ProcurementServiceProvider::class,
    App\BusinessModules\Features\ScheduleManagement\ScheduleManagementServiceProvider::class,
    App\BusinessModules\Features\SiteRequests\SiteRequestsServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
];
