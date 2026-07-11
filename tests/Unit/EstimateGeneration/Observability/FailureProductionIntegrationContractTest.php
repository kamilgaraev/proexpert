<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LegacyDraftPipelineStageAdapter;
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
    public function draft_job_uses_one_container_bound_real_pipeline_entrypoint(): void
    {
        $job = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php');
        $provider = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        self::assertIsString($job);
        self::assertIsString($provider);
        self::assertStringContainsString(DraftPipelineEntrypoint::class, $job);
        self::assertStringNotContainsString('->generate($session)', $job);
        self::assertStringContainsString(LegacyDraftPipelineStageAdapter::class, $provider);
        self::assertStringContainsString(PipelineRunner::class, $provider);
    }

    #[Test]
    public function failure_paths_use_dispatch_or_start_snapshots(): void
    {
        foreach (['GenerateEstimateDraftJob.php', 'ProcessEstimateGenerationDocumentJob.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Jobs/'.$file);
            self::assertIsString($source);
            self::assertStringContainsString('FailureExecutionSnapshot', $source);
            self::assertStringContainsString('$snapshot->stateVersion', $source);
            self::assertStringContainsString('$snapshot->status', $source);
        }
        $ocr = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php');
        self::assertIsString($ocr);
        self::assertStringContainsString('FailureExecutionSnapshot::capture', $ocr);
        self::assertStringContainsString('captureFailure($document, $exception, $snapshot)', $ocr);
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
