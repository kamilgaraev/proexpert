<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentStorageService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class EstimateGenerationOcrCompatibilityTest extends TestCase
{
    public function test_document_parsing_service_uses_new_ocr_pipeline_dependencies(): void
    {
        $constructor = (new ReflectionClass(DocumentParsingService::class))->getConstructor();
        $dependencies = array_map(
            static fn ($parameter): string => (string) $parameter->getType(),
            $constructor?->getParameters() ?? [],
        );

        $this->assertContains(OcrDocumentStorageService::class, $dependencies);
        $this->assertNotContains('App\\BusinessModules\\Addons\\EstimateGeneration\\Services\\Ocr\\OcrUsageLogger', $dependencies);
        $this->assertContainsOnly('string', $dependencies);
    }

    public function test_estimate_generation_module_does_not_reference_legacy_file_parser(): void
    {
        $root = app_path('BusinessModules/Addons/EstimateGeneration');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents((string) $file->getPathname());

            $this->assertIsString($contents);
            $this->assertStringNotContainsString(
                'Services\FileProcessing\FileParserService',
                $contents,
                (string) $file->getPathname(),
            );
        }
    }
}
