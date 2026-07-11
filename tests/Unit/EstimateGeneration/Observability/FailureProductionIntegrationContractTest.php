<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FailureProductionIntegrationContractTest extends TestCase
{
    #[Test]
    public function production_execution_boundaries_require_observability_dependencies(): void
    {
        foreach ([ProcessDocumentUnit::class, PipelineRunner::class, OcrDocumentProcessor::class] as $class) {
            $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
            $byType = [];
            foreach ($parameters as $parameter) {
                $byType[(string) $parameter->getType()] = $parameter;
            }
            foreach ([FailureRecorder::class, FailureWorkflowHandler::class] as $dependency) {
                self::assertArrayHasKey($dependency, $byType, $class);
                self::assertFalse($byType[$dependency]->allowsNull(), $class.' '.$dependency);
                self::assertFalse($byType[$dependency]->isDefaultValueAvailable(), $class.' '.$dependency);
            }
        }
    }

    #[Test]
    public function whole_document_path_records_typed_failure_and_rethrows_recoverable_geometry_or_ocr(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php');
        self::assertIsString($source);
        self::assertStringContainsString('$this->captureFailure(', $source);
        self::assertStringContainsString('FailureCategory::Recoverable', $source);
        self::assertStringContainsString('throw $exception;', $source);
        self::assertStringNotContainsString('PDF geometry extraction skipped', $source);
        self::assertStringNotContainsString('catch (PdfGeometryExtractionException', $source);
    }
}
