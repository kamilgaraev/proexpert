<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Storage\StorageRuntimeConfiguration;
use Illuminate\Support\ServiceProvider;

final class StorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        StorageRuntimeConfiguration::fromConfig(
            (array) config('filesystems'),
            true,
        );
    }
}
