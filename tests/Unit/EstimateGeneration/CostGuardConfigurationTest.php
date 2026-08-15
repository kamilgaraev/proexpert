<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\TestCase;

final class CostGuardConfigurationTest extends TestCase
{
    public function test_bounded_document_and_session_defaults_are_documented_in_the_environment_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $config = file_get_contents($root.'/config/estimate-generation.php');
        $environment = file_get_contents($root.'/.env.example');

        self::assertIsString($config);
        self::assertIsString($environment);
        self::assertStringContainsString(
            "env('ESTIMATE_GENERATION_DOCUMENT_COST_LIMIT_RUB', '600.00')",
            $config,
        );
        self::assertStringContainsString(
            "env('ESTIMATE_GENERATION_SESSION_COST_LIMIT_RUB', '900.00')",
            $config,
        );
        self::assertMatchesRegularExpression(
            "/env\(\s*'ESTIMATE_GENERATION_DOCUMENT_COST_CONFIRMATION_INCREMENT_RUB',\s*'300\.00',?\s*\)/",
            $config,
        );
        self::assertMatchesRegularExpression(
            "/env\(\s*'ESTIMATE_GENERATION_SESSION_COST_CONFIRMATION_INCREMENT_RUB',\s*'450\.00',?\s*\)/",
            $config,
        );
        self::assertStringContainsString('ESTIMATE_GENERATION_DOCUMENT_COST_LIMIT_RUB=600.00', $environment);
        self::assertStringContainsString('ESTIMATE_GENERATION_SESSION_COST_LIMIT_RUB=900.00', $environment);
        self::assertStringContainsString(
            'ESTIMATE_GENERATION_DOCUMENT_COST_CONFIRMATION_INCREMENT_RUB=300.00',
            $environment,
        );
        self::assertStringContainsString(
            'ESTIMATE_GENERATION_SESSION_COST_CONFIRMATION_INCREMENT_RUB=450.00',
            $environment,
        );
    }
}
