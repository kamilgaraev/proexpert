<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance;

use Illuminate\Support\ServiceProvider;

final class HandoverAcceptanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandoverAcceptanceModule::class);
    }
}
