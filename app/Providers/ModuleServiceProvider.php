<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Landing\OrganizationModuleService;
use App\Modules\Services\ModulePermissionService;
use App\Services\Modules\PackageCatalogService;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scopedIf(PackageCatalogService::class);
        $this->app->singleton(OrganizationModuleService::class);
        $this->app->singleton(ModulePermissionService::class);
    }

    public function boot(): void
    {
        //
    }
}
