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

        $expectedPermissions = [
            'estimate_generation.view',
            'estimate_generation.create',
            'estimate_generation.upload_documents',
            'estimate_generation.generate',
            'estimate_generation.review',
            'estimate_generation.select_normative',
            'estimate_generation.export',
            'estimate_generation.apply',
        ];
        $actualPermissions = array_column($manifest['permissions'] ?? [], 'name');
        sort($expectedPermissions);
        sort($actualPermissions);

        self::assertSame($expectedPermissions, $actualPermissions);
    }

    public function test_legacy_ai_estimates_directory_is_removed(): void
    {
        self::assertDirectoryDoesNotExist($this->projectPath('app/BusinessModules/Addons/AIEstimates'));
        self::assertFileExists($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationModule.php'));
    }

    public function test_only_current_estimate_generation_routes_are_registered(): void
    {
        $routes = file_get_contents($this->projectPath(
            'app/BusinessModules/Addons/EstimateGeneration/routes.php'
        ));

        self::assertIsString($routes);
        self::assertStringContainsString("->prefix('api/v1/admin/projects/{project}/estimate-generation/sessions')", $routes);
        self::assertStringNotContainsString("->prefix('api/v1/admin/projects/{project}/ai-estimates')", $routes);
        self::assertStringNotContainsString('AIEstimate', $routes);
    }

    public function test_legacy_permission_aliases_are_removed(): void
    {
        $manifest = file_get_contents($this->projectPath('config/ModuleList/addons/ai-estimates.json'));
        $translations = file_get_contents($this->projectPath('lang/ru/permissions.php'));

        self::assertIsString($manifest);
        self::assertIsString($translations);
        self::assertStringNotContainsString('ai_estimates.', $manifest);
        self::assertStringNotContainsString("'ai_estimates.", $translations);
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
