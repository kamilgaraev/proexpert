<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Routing\Router;
use App\Services\Organization\OrganizationContext;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\MeasurementUnitRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Связываем интерфейс с конкретной реализацией
        $this->app->bind(
            MeasurementUnitRepositoryInterface::class,
            MeasurementUnitRepository::class
        );
        
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
        
        \Illuminate\Support\Facades\Log::info("CORS middleware зарегистрирован");
    }
} 