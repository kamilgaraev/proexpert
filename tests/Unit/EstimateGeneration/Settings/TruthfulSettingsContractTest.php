<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TruthfulSettingsContractTest extends TestCase
{
    #[Test]
    public function schema_v2_contains_only_runtime_consumed_controls(): void
    {
        $payload = $this->command();
        $snapshot = EstimateGenerationSettingsData::fromArray($payload)->snapshot();

        self::assertSame(2, $snapshot['schema_version']);
        self::assertSame(
            ['classification', 'normative_matching', 'vision'],
            $this->sortedKeys($snapshot['models']),
        );
        self::assertSame(
            ['classification', 'geometry', 'normative_matching'],
            $this->sortedKeys($snapshot['confidence']),
        );
        self::assertSame(['low_confidence'], $this->sortedKeys($snapshot['manual_review']));

        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['planning', 'pricing', 'missing_evidence', 'price_outlier', 'normative_fallback'] as $inertControl) {
            self::assertStringNotContainsString($inertControl, $encoded);
        }
    }

    #[Test]
    public function schema_v2_rejects_v1_and_every_removed_control(): void
    {
        foreach ($this->deprecatedCommands() as $payload) {
            try {
                EstimateGenerationSettingsData::fromArray($payload);
                self::fail('Deprecated settings control was accepted.');
            } catch (DomainException) {
            }
        }

        $snapshot = EstimateGenerationSettingsData::fromArray($this->command())->snapshot();
        $snapshot['schema_version'] = 1;

        $this->expectException(DomainException::class);
        EffectiveEstimateGenerationSettings::fromRecord($this->record($snapshot), 17);
    }

    #[Test]
    public function changing_each_remaining_control_changes_effective_runtime_configuration(): void
    {
        $base = EstimateGenerationSettingsData::fromArray($this->command())->snapshot();
        $changed = EstimateGenerationSettingsData::fromArray($this->command([
            'models' => [
                'vision' => 'provider/vision-v3',
                'classification' => 'provider/classification-v3',
                'normative_matching' => 'provider/normative-v3',
            ],
            'limits' => ['max_files' => 9, 'max_pages_per_file' => 99, 'max_total_pages' => 999],
            'timeouts' => ['vision' => 91, 'classification' => 92, 'normative_matching' => 93],
            'retries' => ['vision' => 3, 'classification' => 4, 'normative_matching' => 5],
            'confidence' => ['classification' => '0.6100', 'geometry' => '0.6200', 'normative_matching' => '0.6300'],
            'enabled_formats' => ['pdf'],
            'manual_review' => ['low_confidence' => false],
            'budgets' => ['daily' => '200.00', 'monthly' => '2000.00', 'currency' => 'USD'],
        ]))->snapshot();

        $before = EffectiveEstimateGenerationSettings::fromRecord($this->record($base), 17);
        $after = EffectiveEstimateGenerationSettings::fromRecord($this->record($changed, 42), 17);

        foreach (['vision', 'classification', 'normative_matching'] as $stage) {
            self::assertNotSame($before->model($stage), $after->model($stage));
            self::assertNotSame($before->timeoutSeconds($stage), $after->timeoutSeconds($stage));
            self::assertNotSame($before->retryAttempts($stage), $after->retryAttempts($stage));
        }
        foreach (['classification', 'geometry', 'normative_matching'] as $threshold) {
            self::assertNotSame($before->confidence($threshold), $after->confidence($threshold));
        }
        self::assertNotSame($before->maxFiles(), $after->maxFiles());
        self::assertNotSame($before->maxPagesPerFile(), $after->maxPagesPerFile());
        self::assertNotSame($before->maxTotalPages(), $after->maxTotalPages());
        self::assertNotSame($before->allowsFormat('png'), $after->allowsFormat('png'));
        self::assertNotSame($before->requiresManualReview('low_confidence'), $after->requiresManualReview('low_confidence'));
        self::assertNotSame($before->dailyBudget(), $after->dailyBudget());
        self::assertNotSame($before->monthlyBudget(), $after->monthlyBudget());
        self::assertNotSame($before->currency(), $after->currency());
    }

    #[Test]
    public function runtime_and_filament_sources_have_consumers_for_every_remaining_control(): void
    {
        $root = dirname(__DIR__, 4).'/app/';
        $consumers = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($root.$path),
            [
                'BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php',
                'BusinessModules/Addons/EstimateGeneration/Services/Ocr/Clients/TimewebVisionOcrClient.php',
                'BusinessModules/Addons/EstimateGeneration/Observability/AttemptAwareNormativeLlmClient.php',
                'BusinessModules/Addons/EstimateGeneration/Application/Documents/UploadEstimateGenerationDocuments.php',
                'BusinessModules/Addons/EstimateGeneration/Settings/DocumentRuntimeLimitsGuard.php',
                'BusinessModules/Addons/EstimateGeneration/Observability/AiBudgetGuard.php',
                'BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001150_enforce_exactly_once_ai_budget_wire_claims.php',
            ],
        ));

        foreach ([
            "model('vision')", "model('classification')", "model('normative_matching')",
            "timeoutSeconds('vision')", "timeoutSeconds('classification')", "timeoutSeconds('normative_matching')",
            "retryAttempts('vision')", "retryAttempts('classification')", "retryAttempts('normative_matching')",
            "confidence('classification')", "confidence('geometry')", "confidence('normative_matching')",
            "requiresManualReview('low_confidence')", 'maxFiles()', 'maxPagesPerFile()', 'maxTotalPages()',
            'allowsFormat(', 'daily_budget', 'monthly_budget', 'currency()',
        ] as $consumer) {
            self::assertStringContainsString($consumer, $consumers, "Missing runtime consumer: {$consumer}");
        }

        $page = (string) file_get_contents($root.'Filament/Pages/EstimateGeneration/EstimateGenerationSettings.php');
        foreach (['planning', 'confidence.pricing', 'manual_review.missing_evidence', 'manual_review.price_outlier', 'manual_review.normative_fallback'] as $removed) {
            self::assertStringNotContainsString($removed, $page);
        }
        self::assertStringContainsString("config('estimate-generation.vision.model')", $page);
        self::assertStringContainsString("config('estimate-generation.ocr.model')", $page);
        self::assertStringContainsString('NormativeRerankerModelSet', $page);
    }

    #[Test]
    public function forward_migration_enforces_v2_for_new_rows_without_rewriting_history(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001200_close_truthful_settings_schema.php');

        self::assertStringContainsString('eg_setting_snapshot_valid_v2', $migration);
        self::assertStringContainsString('DROP FUNCTION eg_setting_snapshot_valid_v1', $migration);
        self::assertStringContainsString("payload->>'schema_version' <> '2'", $migration);
        self::assertStringContainsString("ARRAY['vision','classification','normative_matching']", $migration);
        self::assertStringContainsString("ARRAY['classification','geometry','normative_matching']", $migration);
        self::assertStringContainsString("ARRAY['low_confidence']", $migration);
        self::assertStringContainsString('NOT VALID', $migration);
        self::assertStringContainsString("snapshot->>'schema_version' = '2'", $migration);
        self::assertStringNotContainsString('estimate_items', $migration);
        self::assertStringNotContainsString("table('estimates')", $migration);
        self::assertStringNotContainsString('UPDATE estimate_generation_setting_snapshots', $migration);
        self::assertStringNotContainsString('DELETE FROM estimate_generation_setting_snapshots', $migration);
        self::assertStringContainsString('estimate_generation_truthful_settings_schema_is_forward_only', $migration);
    }

    #[Test]
    public function active_admin_and_runtime_paths_neither_select_v1_nor_expose_inert_controls(): void
    {
        $root = dirname(__DIR__, 4).'/app/';
        $activeSources = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($root.$path),
            [
                'BusinessModules/Addons/EstimateGeneration/Settings/EstimateGenerationSettingsData.php',
                'BusinessModules/Addons/EstimateGeneration/Settings/EffectiveEstimateGenerationSettings.php',
                'BusinessModules/Addons/EstimateGeneration/Settings/EstimateGenerationSettingsService.php',
                'BusinessModules/Addons/EstimateGeneration/Settings/EloquentEffectiveSettingsOperationStore.php',
                'Filament/Pages/EstimateGeneration/EstimateGenerationSettings.php',
                'BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_001200_close_truthful_settings_schema.php',
            ],
        ));

        foreach ([
            "'planning'", "'pricing'", "'missing_evidence'", "'price_outlier'", "'normative_fallback'",
            'confidence.pricing',
            'manual_review.missing_evidence', 'manual_review.price_outlier', 'manual_review.normative_fallback',
            "['low_confidence', 'missing_evidence'", "['classification', 'geometry', 'normative_matching', 'pricing']",
        ] as $inertControl) {
            self::assertStringNotContainsString($inertControl, $activeSources);
        }
        self::assertStringNotContainsString("schema_version'] ?? null) !== 1", $activeSources);
        self::assertGreaterThanOrEqual(5, substr_count($activeSources, "snapshot->>'schema_version' = '2'"));
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function command(array $override = []): array
    {
        return array_replace([
            'scope' => 'organization',
            'organization_id' => 17,
            'expected_version' => 3,
            'idempotency_key' => '01J2X5B8YWFK9YD8Q6V1VZ4H3K',
            'models' => [
                'vision' => 'provider/vision-v2',
                'classification' => 'provider/classification-v2',
                'normative_matching' => 'provider/normative-v2',
            ],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 80, 'max_total_pages' => 800],
            'timeouts' => ['vision' => 81, 'classification' => 82, 'normative_matching' => 83],
            'retries' => ['vision' => 0, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7100', 'geometry' => '0.7200', 'normative_matching' => '0.7300'],
            'enabled_formats' => ['pdf', 'png'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ], $override);
    }

    /** @return list<array<string, mixed>> */
    private function deprecatedCommands(): array
    {
        $base = $this->command();

        return [
            array_replace_recursive($base, ['models' => ['planning' => 'provider/planning-v1']]),
            array_replace_recursive($base, ['timeouts' => ['pricing' => 30]]),
            array_replace_recursive($base, ['retries' => ['planning' => 1]]),
            array_replace_recursive($base, ['confidence' => ['pricing' => '0.9000']]),
            array_replace_recursive($base, ['manual_review' => ['missing_evidence' => true]]),
            array_replace_recursive($base, ['manual_review' => ['price_outlier' => true]]),
            array_replace_recursive($base, ['manual_review' => ['normative_fallback' => true]]),
        ];
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function record(array $snapshot, int $snapshotId = 41): array
    {
        return [
            'snapshot_id' => $snapshotId,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $values @return list<string> */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}
