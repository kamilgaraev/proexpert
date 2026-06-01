<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Illuminate\Support\ServiceProvider;

final class DesignManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DesignManagementModule::class);
        $this->app->singleton(Services\DesignStoragePathService::class);
        $this->app->singleton(Services\DesignManagementService::class);
        $this->app->singleton(Services\DesignModelMultipartUploadService::class);
        $this->app->bind(
            Services\Contracts\DesignIfcToFragmentsConverterContract::class,
            Services\DesignIfcToFragmentsConverter::class
        );
        $this->app->bind(
            Services\Contracts\DesignModelMultipartUploader::class,
            static fn ($app): Services\DesignModelMultipartUploadService => $app->make(
                Services\DesignModelMultipartUploadService::class
            )
        );
        $this->app->singleton(Services\DesignPulseFactSource::class);
        $this->app->singleton(S3ClientInterface::class, static function (): S3Client {
            $config = config('filesystems.disks.s3');

            return new S3Client([
                'region' => $config['region'] ?? 'ru-central1',
                'version' => 'latest',
                'credentials' => [
                    'key' => $config['key'] ?? '',
                    'secret' => $config['secret'] ?? '',
                ],
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
            ]);
        });
    }

    public function boot(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }

        $this->app['router']->aliasMiddleware(
            'design-management.active',
            Http\Middleware\EnsureDesignManagementActive::class
        );
    }
}
