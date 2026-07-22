<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\TestCase;

final class EstimateGenerationQueueBackpressureTest extends TestCase
{
    public function test_draft_generation_job_skips_stale_delivery_before_rate_limiting(): void
    {
        $source = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php'));

        self::assertIsString($source);
        self::assertStringNotContainsString('WithoutOverlapping', $source);
        self::assertStringContainsString('Skip::when(fn (): bool => $this->isStale())', $source);
        self::assertLessThan(
            strpos($source, "new RateLimited('estimate-generation-drafts')"),
            strpos($source, 'Skip::when('),
        );
        self::assertStringContainsString('public int $tries = 20;', $source);
        self::assertStringContainsString('public int $maxExceptions = 3;', $source);
        self::assertStringContainsString("new RateLimited('estimate-generation-drafts')", $source);
        self::assertStringContainsString('public function rateLimitKey(): string', $source);
    }

    public function test_draft_generation_continuation_bypasses_entry_rate_limit(): void
    {
        $jobSource = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Jobs/GenerateEstimateDraftJob.php'));
        $runnerSource = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Application/Generation/RunEstimateGenerationDraft.php'));
        $recoverySource = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Application/Generation/RecoverEstimateGenerationPipelines.php'));

        self::assertIsString($jobSource);
        self::assertIsString($runnerSource);
        self::assertIsString($recoverySource);
        self::assertStringContainsString('private readonly bool $throttleEntry = true,', $jobSource);
        self::assertStringContainsString('if ($this->throttleEntry) {', $jobSource);
        self::assertStringContainsString("new RateLimited('estimate-generation-drafts')", $jobSource);
        self::assertStringContainsString('$snapshot->nextEvent(),'."\n".'            false,', $runnerSource);
        self::assertStringContainsString("FailureExecutionSnapshot::capture(\$session, 'recover_generation_pipeline', \$attempt),"."\n".'                false,', $recoverySource);
    }

    public function test_document_dispatcher_and_unit_job_have_separate_backpressure(): void
    {
        $source = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationDocumentJob.php'));

        self::assertIsString($source);
        self::assertStringContainsString('WithoutOverlapping', $source);
        self::assertStringContainsString('estimate-generation:document-dispatch:', $source);
        self::assertStringContainsString("new RateLimited('estimate-generation-ocr-documents')", $source);
        self::assertStringContainsString('public function rateLimitKey(): string', $source);

        $unitSource = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationUnitJob.php'));
        self::assertIsString($unitSource);
        self::assertStringContainsString('estimate-generation:unit:', $unitSource);
        self::assertStringContainsString('->dontRelease()', $unitSource);
        self::assertStringNotContainsString("new RateLimited('estimate-generation-document-units')", $unitSource);
        self::assertStringContainsString('private readonly int $unitId', $unitSource);
        self::assertStringContainsString('private readonly string $sourceVersion', $unitSource);
        self::assertStringNotContainsString('RecoverEstimateGenerationUnitsJob::dispatch()', $unitSource);
    }

    public function test_module_provider_registers_queue_rate_limiters(): void
    {
        $source = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php'));

        self::assertIsString($source);
        self::assertStringContainsString("RateLimiter::for('estimate-generation-drafts'", $source);
        self::assertStringContainsString("RateLimiter::for('estimate-generation-ocr-documents'", $source);
        self::assertStringContainsString("RateLimiter::for('estimate-generation-document-units'", $source);
        self::assertStringContainsString("RateLimiter::for('estimate-generation-training-datasets'", $source);
        self::assertStringContainsString('max_draft_jobs_per_minute', $source);
        self::assertStringContainsString('max_document_jobs_per_minute', $source);
        self::assertStringContainsString('max_dataset_jobs_per_minute', $source);
    }

    public function test_training_dataset_job_has_dataset_and_organization_backpressure(): void
    {
        $source = file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Jobs/ProcessEstimateGenerationTrainingDatasetJob.php'));

        self::assertIsString($source);
        self::assertStringContainsString('WithoutOverlapping', $source);
        self::assertStringContainsString('estimate-generation-training-dataset-', $source);
        self::assertStringContainsString('estimate-generation-training:', $source);
        self::assertStringContainsString("new RateLimited('estimate-generation-training-datasets')", $source);
        self::assertStringContainsString('public function rateLimitKey(): string', $source);
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
