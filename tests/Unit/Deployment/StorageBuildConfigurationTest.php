<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class StorageBuildConfigurationTest extends TestCase
{
    public function test_package_discovery_uses_non_runtime_build_environment(): void
    {
        $dockerfile = file_get_contents(__DIR__.'/../../../Dockerfile.prod');

        self::assertIsString($dockerfile);
        self::assertStringContainsString(
            'APP_ENV=build composer dump-autoload --optimize --no-dev --no-scripts',
            $dockerfile,
        );
        self::assertStringContainsString(
            'APP_ENV=build php artisan package:discover --ansi',
            $dockerfile,
        );
        self::assertStringContainsString(
            'RUN APP_ENV=build php artisan view:cache',
            $dockerfile,
        );
    }
}
