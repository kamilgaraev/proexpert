<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Integration\EstimateGeneration\DocumentArtifactS3IntegrationTest;
use Tests\Integration\EstimateGeneration\VisionPhysicalAttemptPostgresIntegrationTest;
use Tests\Support\ExternalIntegrationGate;

final class CiOnlyExternalGateIsolationTest extends TestCase
{
    #[Test]
    public function external_gate_classes_do_not_inherit_laravel_database_bootstrap(): void
    {
        foreach ([DocumentArtifactS3IntegrationTest::class, VisionPhysicalAttemptPostgresIntegrationTest::class] as $class) {
            $reflection = new ReflectionClass($class);

            self::assertSame(TestCase::class, $reflection->getParentClass()?->getName());
            self::assertNotContains(RefreshDatabase::class, $reflection->getTraitNames());
        }
    }

    #[Test]
    public function absent_opt_in_skips_but_malformed_opt_in_fails_before_external_configuration(): void
    {
        $name = 'MOST_CI_TEST_GATE_'.bin2hex(random_bytes(6));

        try {
            putenv($name);
            self::assertFalse(ExternalIntegrationGate::enabled($name));

            putenv($name.'=yes');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($name.' must be exactly 1');
            ExternalIntegrationGate::enabled($name);
        } finally {
            putenv($name);
        }
    }

    #[Test]
    public function enabled_gate_with_missing_configuration_fails_instead_of_skipping(): void
    {
        $name = 'MOST_CI_TEST_REQUIRED_'.bin2hex(random_bytes(6));

        try {
            putenv($name);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($name.' is required');
            ExternalIntegrationGate::required($name);
        } finally {
            putenv($name);
        }
    }
}
