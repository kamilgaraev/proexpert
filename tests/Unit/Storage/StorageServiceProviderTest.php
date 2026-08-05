<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Providers\StorageServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StorageServiceProviderTest extends TestCase
{
    public function test_it_rejects_incomplete_production_storage_during_boot(): void
    {
        $application = new Application(dirname(__DIR__, 3));
        $application->detectEnvironment(static fn (): string => 'production');
        $application->instance('config', new Repository([
            'filesystems' => [
                'disks' => [
                    's3' => [
                        'driver' => 's3',
                    ],
                ],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_configuration_invalid');

        (new StorageServiceProvider($application))->boot();
    }
}
