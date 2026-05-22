<?php

namespace App\BusinessModules\Features\WorkflowManagement;

use App\BusinessModules\Features\WorkflowManagement\Http\Middleware\EnsureWorkflowManagementActive;
use App\BusinessModules\Features\WorkflowManagement\Services\MobileWorkflowTaskService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class WorkflowManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkflowManagementModule::class);
        $this->app->scoped(MobileWorkflowTaskService::class);
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('workflow-management.active', EnsureWorkflowManagementActive::class);

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }
}
