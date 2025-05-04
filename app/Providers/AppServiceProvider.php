<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
// Удаляем use для репозиториев, так как привязки переносятся
// use App\Repositories\Interfaces\RoleRepositoryInterface;
// use App\Repositories\RoleRepository;
// use App\Repositories\Interfaces\ProjectRepositoryInterface;
// use App\Repositories\ProjectRepository;
// ... (и остальные use для репозиториев)
use Illuminate\Support\Facades\App;
use App\Http\Middleware\CorsMiddleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Router;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Оставляем только не-репозиторные привязки, если они есть
        // $this->app->bind(SomeOtherInterface::class, SomeOtherClass::class);

        // Удаляем все привязки репозиториев отсюда
        // $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        // $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        // $this->app->bind(ProjectRepositoryInterface::class, ProjectRepository::class);
        // $this->app->bind(MaterialRepositoryInterface::class, MaterialRepository::class);
        // $this->app->bind(WorkTypeRepositoryInterface::class, WorkTypeRepository::class);
        // $this->app->bind(SupplierRepositoryInterface::class, SupplierRepository::class);
        // $this->app->bind(MaterialUsageLogRepositoryInterface::class, MaterialUsageLogRepository::class);
        // $this->app->bind(WorkCompletionLogRepositoryInterface::class, WorkCompletionLogRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрация файлов маршрутов API теперь происходит в RouteServiceProvider
        // $this->bootApiRoutes(); 

        // Регистрируем глобальный CORS middleware
        $router = $this->app->make(Router::class);
        $router->prependMiddlewareToGroup('api', CorsMiddleware::class);
        
        // Убеждаемся, что HandlePrecognitiveRequests идет ПОСЛЕ CorsMiddleware
        // Это необходимо для корректной обработки preflight OPTIONS запросов
        if (!App::environment('testing')) {
            $router->pushMiddlewareToGroup('api', HandlePrecognitiveRequests::class);
        }
    }

    // Удаляем весь метод bootApiRoutes и loadRoutesFromSubdirectory
    /*
    protected function bootApiRoutes()
    {
        // ... весь код ...
    }

    protected function loadRoutesFromSubdirectory(string $directoryPath): void
    {
        // ... весь код ...
    }
    */
}
