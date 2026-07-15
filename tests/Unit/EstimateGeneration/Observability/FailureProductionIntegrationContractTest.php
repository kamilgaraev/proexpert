<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RunEstimateGenerationDraft;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ValidateDraftStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FailureProductionIntegrationContractTest extends TestCase
{
    #[Test]
    public function production_execution_boundaries_require_observability_dependencies(): void
    {
        foreach ([ProcessDocumentUnit::class, PipelineRunner::class] as $class) {
            $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
            $byType = [];
            foreach ($parameters as $parameter) {
                $byType[(string) $parameter->getType()] = $parameter;
            }
            $dependencies = $class === ProcessDocumentUnit::class
                ? [FailureRecorder::class]
                : [FailureRecorder::class, FailureWorkflowHandler::class];
            foreach ($dependencies as $dependency) {
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
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $entrypoint = file_get_contents($root.'/Application/Generation/RunEstimateGenerationDraft.php');
        $provider = file_get_contents($root.'/EstimateGenerationServiceProvider.php');
        self::assertIsString($job);
        self::assertIsString($entrypoint);
        self::assertIsString($provider);
        self::assertStringContainsString(RunEstimateGenerationDraft::class, $job);
        self::assertStringContainsString(DraftPipelineEntrypoint::class, $entrypoint);
        self::assertStringContainsString('$generation->handle(', $job);
        self::assertStringNotContainsString('PipelineCheckpointStore', $job);
        self::assertStringNotContainsString('->generate($session)', $job);
        self::assertStringContainsString(ValidateDraftStage::class, $provider);
        self::assertStringNotContainsString('LegacyDraftPipelineStageAdapter', $provider);
        self::assertStringContainsString(PipelineRunner::class, $provider);
    }

    #[Test]
    public function failure_paths_use_dispatch_or_start_snapshots(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application';
        foreach (['Generation/RunEstimateGenerationDraft.php', 'Documents/HandleDocumentProcessingFailure.php'] as $file) {
            $source = file_get_contents($root.'/'.$file);
            self::assertIsString($source);
            self::assertStringContainsString('FailureExecutionSnapshot', $source);
            self::assertStringContainsString('$snapshot->stateVersion', $source);
            self::assertStringContainsString('$snapshot->status', $source);
        }
    }

    #[Test]
    public function document_failure_is_reconciled_without_failing_the_whole_session(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/HandleDocumentProcessingFailure.php');

        self::assertIsString($source);
        self::assertStringContainsString('$this->documents->handle(', $source);
        self::assertStringNotContainsString('FailureWorkflowHandler', $source);
        self::assertStringNotContainsString('$this->workflow->handle(', $source);
    }

    #[Test]
    public function production_claims_revalidate_snapshot_before_work_and_before_publication(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        $checkpoint = file_get_contents($root.'/Pipeline/EloquentPipelineCheckpointStore.php');
        $documentEntrypoint = file_get_contents($root.'/Application/Documents/ProcessEstimateGenerationDocument.php');
        $publication = file_get_contents($root.'/Pipeline/DocumentManifestPublicationFence.php');
        $draftPublication = file_get_contents($root.'/Pipeline/PublishValidatedDraft.php');
        foreach ([$checkpoint, $documentEntrypoint, $publication, $draftPublication] as $source) {
            self::assertIsString($source);
        }
        self::assertStringContainsString('(int) $session->state_version !== $context->stateVersion', $checkpoint);
        self::assertStringContainsString('generation_attempt_id', $checkpoint);
        self::assertStringContainsString('DocumentSourceVersion::fromDocument($document)', $checkpoint);
        self::assertStringContainsString('$this->checkpoints->claim(', $documentEntrypoint);
        self::assertStringContainsString('$this->creator->handleClaimed($document, $claim)', $documentEntrypoint);
        self::assertStringContainsString('final readonly class ProcessEstimateGenerationDocument', $documentEntrypoint);
        self::assertStringContainsString("->where('claim_token', \$claim->claimToken)", $publication);
        self::assertStringContainsString("->where('state_version', \$context->stateVersion)", $publication);
        self::assertStringContainsString('(int) $session->state_version !== $claim->context->stateVersion', $draftPublication);
        self::assertStringContainsString('generation_attempt_id', $draftPublication);
        $creator = file_get_contents($root.'/Application/Documents/CreateDocumentProcessingUnits.php');
        self::assertIsString($creator);
        self::assertStringNotContainsString('EstimateGenerationUnitJobDispatcher', $creator);
        self::assertStringNotContainsString('->forDocument(', $creator);
        $recovery = file_get_contents($root.'/Jobs/RecoverEstimateGenerationUnitsJob.php');
        self::assertIsString($recovery);
        self::assertStringContainsString('$dispatcher->recover()', $recovery);
    }

    #[Test]
    public function dormant_whole_document_processor_is_absent_from_production_and_container(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration';
        self::assertFileDoesNotExist($root.'/Services/Ocr/OcrDocumentProcessor.php');
        $provider = file_get_contents($root.'/EstimateGenerationServiceProvider.php');
        self::assertIsString($provider);
        self::assertStringNotContainsString('OcrDocumentProcessor', $provider);
        $references = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'OcrDocumentProcessor')) {
                $references[] = $file->getPathname();
            }
        }
        self::assertSame([], $references);
    }
}
