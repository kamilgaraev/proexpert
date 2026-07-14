<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Settings;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionSettingsDependencyInjectionTest extends TestCase
{
    #[Test]
    public function production_provider_bindings_never_bypass_settings_or_budget_in_tests(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        self::assertIsString($provider);

        self::assertStringNotContainsString('runningUnitTests()', $provider);
        self::assertStringContainsString('EffectiveSettingsOperationStore::class', $provider);
        self::assertStringContainsString('AiAttemptAuthorizer::class', $provider);
    }
}
