<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Routing\Router;
use App\Services\Organization\OrganizationContext;
use App\Services\FileService;
use App\Services\Export\ExcelExporterService;
use App\Services\Report\MaterialReportService;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\OrganizationRoleService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Регистрируем FileService как singleton
        $this->app->singleton(FileService::class, function ($app) {
            return new FileService($app->make(\Illuminate\Contracts\Filesystem\Factory::class));
        });
        // Регистрируем ExcelExporterService как singleton
        $this->app->singleton(ExcelExporterService::class, function ($app) {
            return new ExcelExporterService();
        });
        
        // Регистрируем MaterialReportService как singleton
        $this->app->singleton(MaterialReportService::class, function ($app) {
            return new MaterialReportService();
        });

        $this->app->singleton(ChildOrganizationUserService::class, function ($app) {
            return new ChildOrganizationUserService($app->make(OrganizationRoleService::class));
        });
        
        // Здесь могут быть другие связывания
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрируем CORS middleware
        $router = $this->app->make(Router::class);
        
        // Добавляем middleware в группы
        $router->pushMiddlewareToGroup("web", CorsMiddleware::class);
        $router->pushMiddlewareToGroup("api", CorsMiddleware::class);
        
        // Добавляем его первым в группе api
        if (method_exists($router, "prependToGroup")) {
            $router->prependToGroup("api", CorsMiddleware::class);
        } else {
            $router->prependMiddlewareToGroup("api", CorsMiddleware::class);
        }
        
    }
} 