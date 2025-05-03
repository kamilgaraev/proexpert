<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use App\Repositories\RoleRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Явная привязка для диагностики
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        
        // Возможно, стоит добавить и для UserRepositoryInterface на всякий случай?
        // $this->app->bind(\App\Repositories\UserRepositoryInterface::class, \App\Repositories\UserRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Регистрация файлов маршрутов API теперь происходит в RouteServiceProvider
        // $this->bootApiRoutes(); 
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
