<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Landing\OrganizationModuleService;
use App\Services\Landing\ModulePermissionService;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrganizationModuleService::class);
        $this->app->singleton(ModulePermissionService::class);
    }

    public function boot(): void
    {
        //
    }
} 