<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // TODO: Добавить новые политики для новой системы авторизации
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // TODO: Здесь будут новые Gates и политики для новой системы авторизации
        // Пример:
        // Gate::define('access-admin-panel', [NewAuthorizationService::class, 'checkAdminAccess']);
    }
}
