<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedEstimateRebuildBoundaryTest extends TestCase
{
    #[Test]
    public function it_keeps_targeted_rebuild_code_outside_the_mass_generation_path(): void
    {
        $directory = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild';
        $source = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            glob($directory.'/*.php') ?: [],
        ));

        foreach ([
            'RebuildGeneratedSection',
            'GenerateEstimateDraftJob',
            'DraftPipelineEntrypoint',
            'PublishValidatedDraft',
            'syncFromDraft',
            'dispatch(',
            'onQueue(',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function it_requires_the_exact_existing_package_writer_without_historical_sync(): void
    {
        $path = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('function syncPackageFromDraft(', $source);
        self::assertStringNotContainsString('syncFromDraft(', $this->methodBody($source, 'syncPackageFromDraft'));
        self::assertStringNotContainsString('retainHistoricalPackages(', $this->methodBody($source, 'syncPackageFromDraft'));
        self::assertStringNotContainsString('updateOrCreate(', $this->methodBody($source, 'syncPackageFromDraft'));
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        self::assertNotFalse($start);
        $next = strpos($source, "\n    /**", $start + 1);

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
