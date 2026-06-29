<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EstimateGenerationPackagePersistenceServiceTest extends TestCase
{
    public function test_draft_package_keys_are_stable_unique_and_ignore_invalid_rows(): void
    {
        $service = new EstimateGenerationPackagePersistenceService();
        $method = new ReflectionMethod($service, 'draftPackageKeys');
        $method->setAccessible(true);

        $keys = $method->invoke($service, [
            'local_estimates' => [
                ['key' => 'foundation'],
                ['title' => 'Package without explicit key'],
                'invalid',
                ['key' => 'foundation'],
            ],
        ]);

        self::assertSame(['foundation', 'package-2'], $keys);
    }
}
