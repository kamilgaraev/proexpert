<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\EstimateGenerationServiceProvider;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationPlan2CorrectiveContractTest extends TestCase
{
    #[Test]
    public function snapshot_uses_text_safe_watermark_and_exact_closed_statuses(): void
    {
        $source = $this->source('Application/Sessions/BuildSessionOperationalSnapshot.php');

        self::assertStringNotContainsString('SUM(source_version)', $source);
        self::assertStringContainsString("string_agg(id::text || ':' || COALESCE(source_version, '')", $source);
        self::assertStringNotContainsString("status IN ('pending','retry_scheduled')", $source);
        self::assertStringNotContainsString("status IN ('pending','queued')", $source);
        self::assertStringNotContainsString("status = 'processing'", $source);
        foreach (CheckpointStatus::cases() as $status) {
            self::assertStringContainsString('CheckpointStatus::'.$status->name, $source);
        }
        foreach (DocumentProcessingUnitStatus::cases() as $status) {
            self::assertStringContainsString('DocumentProcessingUnitStatus::'.$status->name, $source);
        }
    }

    #[Test]
    public function planner_and_gateway_do_not_eager_load_the_whole_source_graph(): void
    {
        $planner = $this->source('Pipeline/EloquentPipelineExecutionPlanner.php');
        $baseInputResolver = $this->source('Pipeline/EvidenceAwarePipelineBaseInputVersionResolver.php');
        $gateway = $this->source('Pipeline/EloquentGenerationPipelineDataGateway.php');

        self::assertStringNotContainsString('->with([', $planner);
        self::assertStringNotContainsString('string_agg', $planner);
        self::assertStringNotContainsString('to_jsonb', $planner);
        self::assertStringContainsString('EvidenceAwarePipelineBaseInputVersionResolver', $planner);
        self::assertStringContainsString("selectRaw('COUNT(*) AS source_count')", $baseInputResolver);
        self::assertStringContainsString('BoundedSourceVersionHasher', $baseInputResolver);
        self::assertStringContainsString("hash_init('sha256')", $this->source('Pipeline/BoundedSourceVersionHasher.php'));
        self::assertStringContainsString("orderBy('document_id')->orderBy('id')->cursor()", $baseInputResolver);
        self::assertLessThan(strpos($baseInputResolver, '->cursor()'), strpos($baseInputResolver, "selectRaw('COUNT(*) AS source_count')"));
        self::assertStringNotContainsString('documents.facts', $planner);
        self::assertStringNotContainsString('documents.drawingElements', $planner);
        self::assertStringNotContainsString('documents.quantityTakeoffs', $planner);
        self::assertStringNotContainsString('documents.scopeInferences', $planner);
        self::assertStringNotContainsString('->with([', $gateway);
        self::assertStringContainsString('MAX_SOURCE_ROWS', $gateway);
        self::assertStringContainsString('MAX_SOURCE_BYTES', $gateway);
        self::assertStringContainsString('cursor(', $gateway);
    }

    #[Test]
    public function every_stage_is_lease_aware_and_runtime_margin_exceeds_job_timeout(): void
    {
        $provider = $this->source('EstimateGenerationServiceProvider.php');
        $horizon = $this->rootSource('config/horizon.php');

        self::assertStringContainsString('MINIMUM_PIPELINE_LEASE_SECONDS', $provider);
        self::assertStringContainsString("'timeout' => 1800", $horizon);
        $job = (new ReflectionClass(GenerateEstimateDraftJob::class))->newInstanceWithoutConstructor();
        self::assertGreaterThan($job->timeout, EstimateGenerationServiceProvider::MINIMUM_PIPELINE_LEASE_SECONDS);
        $leaseBehavior = $this->source('Pipeline/RenewsPipelineLease.php');
        self::assertStringContainsString('executeWithHeartbeat', $leaseBehavior);
        self::assertStringContainsString('->renew()', $leaseBehavior);
        foreach (ProcessingStage::cases() as $stage) {
            $class = 'App\\BusinessModules\\Addons\\EstimateGeneration\\Pipeline\\Stages\\'
                .str_replace(' ', '', ucwords(str_replace('_', ' ', $stage->value))).'Stage';
            self::assertTrue(is_subclass_of($class, LeaseAwarePipelineStage::class), $class);
            $source = $this->rootSource((new ReflectionClass($class))->getFileName());
            self::assertStringContainsString('use RenewsPipelineLease;', $source);
        }
    }

    #[Test]
    public function operational_snapshot_statuses_match_enums_and_database_checks(): void
    {
        $snapshot = $this->source('Application/Sessions/BuildSessionOperationalSnapshot.php');
        $checkpointMigration = $this->rootSource('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000100_create_estimate_generation_pipeline_checkpoints_table.php');
        $unitMigration = $this->rootSource('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000200_create_estimate_generation_processing_units_table.php');

        $this->assertEnumParity(CheckpointStatus::cases(), $checkpointMigration, $snapshot);
        $this->assertEnumParity(DocumentProcessingUnitStatus::cases(), $unitMigration, $snapshot);
    }

    #[Test]
    public function processing_unit_migration_owns_composite_tenant_identities_and_foreign_keys(): void
    {
        $units = $this->rootSource('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000200_create_estimate_generation_processing_units_table.php');
        $usage = $this->rootSource('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php');

        foreach (['eg_documents_tenant_scope_uq', 'eg_units_tenant_scope_uq', 'eg_pages_tenant_scope_uq', 'eg_units_session_scope_fk', 'eg_units_document_scope_fk', 'eg_pages_unit_scope_fk'] as $name) {
            self::assertStringContainsString($name, $units);
        }
        self::assertStringNotContainsString('eg_documents_tenant_scope_uq', $usage);
        self::assertStringNotContainsString('eg_units_tenant_scope_uq', $usage);
        self::assertStringNotContainsString('eg_pages_tenant_scope_uq', $usage);
    }

    private function source(string $relative): string
    {
        return $this->rootSource('app/BusinessModules/Addons/EstimateGeneration/'.$relative);
    }

    private function rootSource(string $relative): string
    {
        $path = str_starts_with($relative, dirname(__DIR__, 2)) ? $relative : dirname(__DIR__, 2).'/'.$relative;
        $source = file_get_contents($path);
        self::assertIsString($source, $path);

        return $source;
    }

    /** @param list<\BackedEnum> $cases */
    private function assertEnumParity(array $cases, string $migration, string $snapshot): void
    {
        foreach ($cases as $case) {
            self::assertStringContainsString("'{$case->value}'", $migration);
            self::assertStringContainsString((new ReflectionClass($case::class))->getShortName().'::'.$case->name, $snapshot);
        }
    }
}
