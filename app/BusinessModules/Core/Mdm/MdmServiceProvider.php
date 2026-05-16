<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm;

use App\BusinessModules\Core\Mdm\Console\Commands\MdmInspectCommand;
use App\BusinessModules\Core\Mdm\Console\Commands\MdmSyncCommand;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmChangeRequestService;
use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmImportService;
use App\BusinessModules\Core\Mdm\Services\MdmNormalizationService;
use App\BusinessModules\Core\Mdm\Services\MdmQualityService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use Illuminate\Support\ServiceProvider;

class MdmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MdmEntityRegistry::class);
        $this->app->singleton(MdmNormalizationService::class);
        $this->app->singleton(MdmQualityService::class);
        $this->app->singleton(MdmRecordService::class);
        $this->app->singleton(MdmChangeRequestService::class);
        $this->app->singleton(MdmDuplicateDetectionService::class);
        $this->app->singleton(MdmRelationshipService::class);
        $this->app->singleton(MdmImportService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MdmInspectCommand::class,
                MdmSyncCommand::class,
            ]);
        }
    }
}
