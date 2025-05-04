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
        // Регистрируем CORS middleware в качестве глобального middleware
        // с более высоким приоритетом, чем все другие (он выполнится первым)
        $router = $this->app->make(Router::class);
        
        // Добавляем как глобальный middleware (для всех маршрутов)
        $router->pushMiddlewareToGroup('web', CorsMiddleware::class);
        $router->pushMiddlewareToGroup('api', CorsMiddleware::class);
        
        // Также добавляем его первым в middleware группе api
        // для обработки OPTIONS-запросов перед всеми остальными middleware
        if (method_exists($router, 'prependToGroup')) {
            $router->prependToGroup('api', CorsMiddleware::class);
        } else {
            // Альтернативный способ для Laravel 11
            $router->prependMiddlewareToGroup('api', CorsMiddleware::class);
        }
        
        // Логирование для отладки
        \Illuminate\Support\Facades\Log::info('CORS middleware зарегистрирован в AppServiceProvider');
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
