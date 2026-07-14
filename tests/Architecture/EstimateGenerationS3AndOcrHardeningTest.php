<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationS3AndOcrHardeningTest extends TestCase
{
    #[Test]
    public function pipeline_artifacts_use_the_canonical_tenant_and_physical_attempt_prefix(): void
    {
        $source = $this->source('Pipeline/S3PipelineArtifactStore.php');

        self::assertStringContainsString('estimate-generation/sessions/%d/pipeline/attempts/%s', $source);
        self::assertStringNotContainsString('ai-estimator/', $source);
        self::assertStringNotContainsString("?? 'legacy'", $source);
        self::assertStringContainsString('generation_attempt_required', $source);
        self::assertStringContainsString('putImmutable(', $source);
        $workflow = file_get_contents(dirname(__DIR__, 2).'/docs/workflows/ai-estimator.md');
        $runbook = file_get_contents(dirname(__DIR__, 2).'/docs/runbooks/ai-estimator-production-readiness.md');
        self::assertIsString($workflow);
        self::assertIsString($runbook);
        self::assertStringNotContainsString('/ai-estimator/sessions/', $workflow);
        self::assertStringContainsString('org-*/estimate-generation/sessions/*/pipeline/attempts/*/', $runbook);
    }

    #[Test]
    public function pipeline_and_document_unit_consumers_share_a_bounded_versioned_reader(): void
    {
        $reader = $this->source('Storage/BoundedVersionedS3ObjectReader.php');
        $pipeline = $this->source('Pipeline/S3PipelineArtifactStore.php');
        $unitReader = $this->source('Application/Documents/S3DocumentUnitContentReader.php');
        $processor = $this->source('Application/Documents/ProductionDocumentUnitProcessor.php');

        self::assertStringContainsString('describeVersion(', $reader);
        self::assertStringContainsString('expectedBytes', $reader);
        self::assertStringContainsString('expectedSha256', $reader);
        self::assertStringContainsString('versionId', $reader);
        self::assertStringContainsString('BoundedVersionedS3ObjectReader', $pipeline);
        self::assertStringContainsString('BoundedVersionedS3ObjectReader', $unitReader);
        self::assertStringContainsString('BoundedVersionedS3ObjectReader', $processor);
        foreach ([$pipeline, $unitReader, $processor] as $consumer) {
            self::assertStringNotContainsString('->get(', $consumer);
            self::assertStringNotContainsString('stream_get_contents(', $consumer);
        }
    }

    #[Test]
    public function production_ocr_has_one_pinned_model_and_no_model_list_route(): void
    {
        $client = $this->source('Services/Ocr/Clients/TimewebVisionOcrClient.php');
        $config = file_get_contents(dirname(__DIR__, 2).'/config/estimate-generation.php');
        $environment = file_get_contents(dirname(__DIR__, 2).'/.env.example');

        self::assertIsString($config);
        self::assertIsString($environment);
        self::assertStringNotContainsString('modelsFor(', $client);
        self::assertStringNotContainsString('fallbackToAnotherModel(', $client);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OCR_MODELS', $config);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OCR_PDF_MODELS', $config);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OCR_MODELS=', $environment);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OCR_PDF_MODELS=', $environment);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/'.$relative);
        self::assertIsString($source);

        return $source;
    }
}
