<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStageArchitectureTest extends TestCase
{
    #[Test]
    public function production_has_exactly_nine_concrete_stage_classes_and_no_legacy_orchestrator(): void
    {
        $root = dirname(__DIR__, 4);
        $stageDirectory = $root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages';
        $expected = array_map(
            static fn (ProcessingStage $stage): string => $stageDirectory.'/'.self::className($stage).'.php',
            ProcessingStage::cases(),
        );

        self::assertCount(9, $expected);
        foreach ($expected as $path) {
            self::assertFileExists($path);
        }
        self::assertFileDoesNotExist($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/LegacyDraftPipelineStageAdapter.php');
        self::assertFileDoesNotExist($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationOrchestrator.php');

        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        self::assertIsString($provider);
        self::assertStringNotContainsString('LegacyDraftPipelineStageAdapter', $provider);
        self::assertStringNotContainsString('EstimateGenerationOrchestrator', $provider);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root.'/app/BusinessModules/Addons/EstimateGeneration',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertStringNotContainsString('LegacyDraftPipelineStageAdapter', $source, $file->getPathname());
            self::assertStringNotContainsString('EstimateGenerationOrchestrator', $source, $file->getPathname());
        }
    }

    private static function className(ProcessingStage $stage): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $stage->value))).'Stage';
    }
}
