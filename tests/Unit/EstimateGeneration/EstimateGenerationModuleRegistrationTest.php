<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\TestCase;

final class EstimateGenerationModuleRegistrationTest extends TestCase
{
    public function test_estimate_generation_is_the_only_ai_estimate_runtime_module(): void
    {
        $providers = file_get_contents($this->projectPath('bootstrap/providers.php'));

        self::assertIsString($providers);
        self::assertStringContainsString(
            'App\\BusinessModules\\Addons\\EstimateGeneration\\EstimateGenerationServiceProvider::class',
            $providers
        );
        self::assertStringNotContainsString('AIEstimatesServiceProvider', $providers);
    }

    public function test_ai_estimates_manifest_points_to_estimate_generation_module(): void
    {
        $manifest = json_decode(
            (string) file_get_contents($this->projectPath('config/ModuleList/addons/ai-estimates.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            'App\\BusinessModules\\Addons\\EstimateGeneration\\EstimateGenerationModule',
            $manifest['class_name'] ?? null
        );
    }

    public function test_legacy_ai_estimates_directory_is_removed(): void
    {
        self::assertDirectoryDoesNotExist($this->projectPath('app/BusinessModules/Addons/AIEstimates'));
        self::assertFileExists($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationModule.php'));
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
