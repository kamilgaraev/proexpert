<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement;

use Illuminate\Support\ServiceProvider;

final class ChangeManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChangeManagementModule::class);
    }
}
