<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class EstimateGenerationPipelineArchitectureTest extends TestCase
{
    #[Test]
    public function module_has_one_pipeline_and_no_replaced_orchestration(): void
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';

        self::assertFileDoesNotExist($root.'/Services/EstimateGenerationOrchestrator.php');
        self::assertFileDoesNotExist($root.'/Pipeline/LegacyDraftPipelineStageAdapter.php');
        self::assertFileDoesNotExist($root.'/Services/Ocr/OcrDocumentProcessor.php');
        self::assertFileDoesNotExist($root.'/Services/Ocr/DocumentProcessingStatusService.php');

        foreach ($this->phpSources($root) as $path => $source) {
            self::assertStringNotContainsString('EstimateGenerationOrchestrator', $source, $path);
            self::assertStringNotContainsString('LegacyDraftPipelineStageAdapter', $source, $path);
            self::assertStringNotContainsString('OcrDocumentProcessor', $source, $path);
            self::assertStringNotContainsString('DocumentProcessingStatusService', $source, $path);
        }
    }

    #[Test]
    public function transport_layers_do_not_chain_pipeline_stages_or_persistence(): void
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';

        foreach (['Http/Controllers', 'Console/Commands'] as $relativeDirectory) {
            foreach ($this->phpSources($root.'/'.$relativeDirectory) as $path => $source) {
                self::assertDoesNotMatchRegularExpression('/Pipeline\\\\Stages\\\\|GenerationPipelineDataGateway|PipelineCheckpointStore/', $source, $path);
                self::assertStringNotContainsString('->execute(', $source, $path);
            }
        }

        foreach ($this->phpSources($root.'/Jobs') as $path => $source) {
            self::assertDoesNotMatchRegularExpression(
                '/Pipeline\\\\Stages\\\\|GenerationPipelineDataGateway|PipelineCheckpointStore|FailureRecorder|FailureWorkflowHandler|CreateDocumentProcessingUnits/',
                $source,
                $path,
            );
        }
    }

    /** @return array<string, string> */
    private function phpSources(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $sources = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $sources[$file->getPathname()] = $source;
        }

        return $sources;
    }
}
