<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

final class EstimateGenerationProductionReadinessTest extends TestCase
{
    #[Test]
    public function queues_have_workers_recovery_schedules_and_bounded_jobs(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = $this->source($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        $horizon = $this->source($root.'/config/horizon.php');

        foreach (['estimate-generation', 'estimate-generation-units', 'estimate-generation-unit-maintenance'] as $queue) {
            self::assertStringContainsString("'{$queue}'", $horizon);
        }
        foreach (['RecoverEstimateGenerationUnitsJob', 'RecoverEstimateGenerationPipelinesJob', 'DeliverEstimateGenerationFinalizationsJob'] as $job) {
            self::assertStringContainsString("->job(new {$job})", $provider);
        }
        self::assertGreaterThanOrEqual(3, substr_count($provider, '->withoutOverlapping()'));
    }

    #[Test]
    public function durable_artifacts_use_s3_and_no_persistent_local_preview_path(): void
    {
        $root = dirname(__DIR__, 2);
        $config = $this->source($root.'/config/estimate-generation.php');
        $artifactStore = $this->source($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/S3PipelineArtifactStore.php');
        $manifestStore = $this->source($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/S3DocumentSourceManifestStorage.php');

        self::assertStringNotContainsString('preview_dir', $config);
        self::assertStringNotContainsString("storage_path('app/estimate-generation", $config);
        self::assertStringContainsString('FileService', $artifactStore);
        self::assertStringContainsString("'private'", $artifactStore);
        self::assertStringContainsString('FileService', $manifestStore);
        self::assertStringContainsString("'private'", $manifestStore);
    }

    #[Test]
    public function production_image_keeps_runtime_code_root_owned_and_only_runtime_state_writable(): void
    {
        $dockerfile = $this->source(dirname(__DIR__, 2).'/Dockerfile.prod');
        self::assertDoesNotMatchRegularExpression('/chown -R www-data:www-data \$\{APP_DIR\}\s+\\\\/', $dockerfile);
        self::assertStringContainsString('chown -R root:root ${APP_DIR}', $dockerfile);
        self::assertStringContainsString('chmod -R go-w ${APP_DIR}', $dockerfile);
        self::assertStringContainsString('chown -R www-data:www-data ${APP_DIR}/storage ${APP_DIR}/bootstrap/cache', $dockerfile);
        self::assertStringContainsString('chmod 0555 "${ESTIMATE_GENERATION_CAD_SCRIPT}"', $dockerfile);
        self::assertStringContainsString('chmod 0444 "${ESTIMATE_GENERATION_CAD_REQUIREMENTS_LOCK}"', $dockerfile);
        self::assertStringContainsString('sha256sum /opt/geometry-venv/bin/python /opt/libredwg/bin/dwgread /usr/local/bin/geometry-sandbox', $dockerfile);
        self::assertStringContainsString('chmod 0444 /etc/most/cad-runtime.sha256', $dockerfile);
    }

    #[Test]
    public function plan_migrations_are_uniquely_ordered_and_reversible_in_reverse_dependency_order(): void
    {
        $directory = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/migrations';
        $paths = glob($directory.'/2026_07_11_*.php');
        self::assertIsArray($paths);
        sort($paths, SORT_STRING);

        $names = array_map('basename', $paths);
        $registeredNames = array_values(array_map(
            'basename',
            array_filter(
                EstimateGenerationContractDatabaseProvisioner::completeInventory(),
                static fn (string $path): bool => str_starts_with(
                    $path,
                    'app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_',
                ),
            ),
        ));
        self::assertSame($registeredNames, $names);

        $combined = implode("\n", array_map(fn (string $path): string => $this->source($path), $paths));
        foreach (['pipeline_checkpoints', 'processing_units', 'estimate_generation_evidence', 'ai_usage', 'failure_events', 'finalization_outbox'] as $contract) {
            self::assertStringContainsString($contract, $combined);
        }
        self::assertStringNotContainsString("Schema::table('estimates'", $combined);
        self::assertStringNotContainsString("Schema::table('estimate_items'", $combined);
        self::assertStringNotContainsString("Schema::table('estimate_sections'", $combined);

        $evidenceDown = strstr($this->source($paths[3]), 'public function down');
        $failureDown = strstr($this->source($paths[5]), 'public function down');
        $outboxDown = strstr($this->source($paths[6]), 'public function down');
        self::assertIsString($evidenceDown);
        self::assertIsString($failureDown);
        self::assertIsString($outboxDown);
        self::assertLessThan(strpos($evidenceDown, "dropIfExists('estimate_generation_evidence')"), strpos($evidenceDown, "dropIfExists('estimate_generation_evidence_edges')"));
        self::assertLessThan(strpos($failureDown, "dropIfExists('estimate_generation_failure_identities')"), strpos($failureDown, "dropIfExists('estimate_generation_failure_events')"));
        self::assertLessThan(strpos($outboxDown, "dropIfExists('estimate_generation_finalization_outbox')"), strpos($outboxDown, "dropIfExists('estimate_generation_finalization_deliveries')"));
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, $path);

        return $source;
    }
}
