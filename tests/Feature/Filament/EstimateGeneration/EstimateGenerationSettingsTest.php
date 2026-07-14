<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Filament\Pages\EstimateGeneration\EstimateGenerationSettings;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationSettingsTest extends TestCase
{
    #[Test]
    public function scope_switch_reloads_exact_snapshot_and_baseline_atomically(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/Filament/Pages/EstimateGeneration/EstimateGenerationSettings.php');
        self::assertIsString($source);
        self::assertStringContainsString('afterStateUpdated', $source);
        self::assertStringContainsString('reloadScopeSnapshot', $source);
        self::assertStringContainsString('$scopeEpoch', $source);
        self::assertStringContainsString("'expected_version' => 0", $source);
        self::assertStringContainsString("currentSnapshot('organization'", $source);
        self::assertStringContainsString("currentSnapshot('global', null)", $source);
        self::assertStringContainsString("'organization_id' => null", $source);
    }

    #[Test]
    public function scope_state_covers_global_first_organization_existing_organization_and_tenant_isolation(): void
    {
        $defaults = ['models' => ['vision' => 'default'], 'organization_id' => null, 'expected_version' => 0];
        $global = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsScopeState::compose(
            $defaults, 'global', null,
            ['scope' => 'global', 'organization_id' => null, 'version' => 3, 'snapshot' => ['models' => ['vision' => 'global']]],
            '01GLOBALSETTINGSKEY',
        );
        self::assertSame(3, $global['expected_version']);
        self::assertNull($global['organization_id']);

        $firstOrganization = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsScopeState::compose(
            $defaults, 'organization', 71, null, '01FIRSTORGSETTINGS',
        );
        self::assertSame(0, $firstOrganization['expected_version']);
        self::assertSame(71, $firstOrganization['organization_id']);

        $existing = \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsScopeState::compose(
            $defaults, 'organization', 71,
            ['scope' => 'organization', 'organization_id' => 71, 'version' => 4, 'snapshot' => ['models' => ['vision' => 'org-71']]],
            '01EXISTINGORGSETTINGS',
        );
        self::assertSame(4, $existing['expected_version']);
        self::assertSame(['vision' => 'org-71'], $existing['models']);

        $this->expectException(DomainException::class);
        \App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsScopeState::compose(
            $defaults, 'organization', 71,
            ['scope' => 'organization', 'organization_id' => 72, 'version' => 1, 'snapshot' => []],
            '01CROSSTENANTSETTING',
        );
    }

    public function test_closed_schema_builds_canonical_secret_free_snapshot(): void
    {
        $data = EstimateGenerationSettingsData::fromArray($this->validPayload());
        $snapshot = $data->snapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        self::assertSame('organization', $data->scope);
        self::assertSame(17, $data->organizationId);
        self::assertSame('1000.00', $snapshot['budgets']['monthly']);
        self::assertSame('100.00', $snapshot['budgets']['daily']);
        self::assertSame('RUB', $snapshot['budgets']['currency']);
        self::assertSame(['pdf', 'png', 'xlsx'], $snapshot['enabled_formats']);
        foreach (['api_key', 'secret', 'credential', 'endpoint', 'prompt', 'password', 'token'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($encoded));
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown root key' => [['api_key' => 'secret']];
        yield 'embedded endpoint' => [['models' => ['vision' => 'https://secret.example/model']]];
        yield 'unknown model stage' => [['models' => ['export' => 'provider/model']]];
        yield 'float money' => [['budgets' => ['monthly' => 1000.0]]];
        yield 'excess money scale' => [['budgets' => ['monthly' => '1000.001']]];
        yield 'unknown currency' => [['budgets' => ['currency' => 'BTC']]];
        yield 'unknown format' => [['enabled_formats' => ['exe']]];
        yield 'global organization leak' => [['scope' => 'global', 'organization_id' => 17]];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidPayloads')]
    public function test_closed_schema_rejects_unknown_secret_and_inexact_values(array $override): void
    {
        $this->expectException(DomainException::class);
        EstimateGenerationSettingsData::fromArray(array_replace_recursive($this->validPayload(), $override));
    }

    public function test_change_set_service_uses_cas_idempotency_immutable_snapshot_and_audit(): void
    {
        $source = $this->source(EstimateGenerationSettingsService::class);

        foreach (['expectedVersion', 'idempotencyKey', 'commandFingerprint', 'lockForUpdate', '\'version\' => $currentVersion + 1', 'estimate_generation_setting_audits', 'old_value', 'new_value'] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        self::assertStringNotContainsString('updateOrInsert', $source);
        self::assertStringNotContainsString("table('estimate_generation_settings')->update", $source);
    }

    public function test_filament_page_uses_closed_data_and_never_renders_secret_fields(): void
    {
        $source = $this->source(EstimateGenerationSettings::class);

        self::assertStringContainsString('EstimateGenerationSettingsData::fromArray', $source);
        self::assertStringContainsString('EstimateGenerationSettingsService::class', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_SETTINGS', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_BUDGETS', $source);
        foreach (['api_key', 'secret', 'credential', 'endpoint', 'raw_prompt', 'password', 'token'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    public function test_migration_has_versioned_scope_money_idempotency_and_immutability_contracts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_002000_create_estimate_generation_settings_and_budgets.php');
        self::assertIsString($source);

        foreach (['estimate_generation_setting_snapshots', 'estimate_generation_setting_audits', 'estimate_generation_setting_operations', 'scope', 'organization_id', 'version', 'snapshot', 'daily_budget', 'monthly_budget', 'currency', 'idempotency_key', 'command_fingerprint'] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
        self::assertStringContainsString("decimal('daily_budget', 20, 2)", $source);
        self::assertStringContainsString("decimal('monthly_budget', 20, 2)", $source);
        self::assertStringContainsString("scope IN ('global','organization')", $source);
        self::assertStringContainsString("currency IN ('RUB','USD','EUR')", $source);
        self::assertStringContainsString('CREATE TRIGGER', $source);
        self::assertStringContainsString('immutable', strtolower($source));
        self::assertStringContainsString('NULLS NOT DISTINCT', $source);
        self::assertStringContainsString('eg_setting_snapshot_valid_v1', $source);
        self::assertStringContainsString("ARRAY['vision','classification','planning','normative_matching','pricing']", $source);
        self::assertStringContainsString("ARRAY['low_confidence','missing_evidence','price_outlier','normative_fallback']", $source);
    }

    public function test_settings_snapshot_hash_is_added_by_an_ordered_upgrade_migration(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $historical = file_get_contents($root.'2026_07_11_002000_create_estimate_generation_settings_and_budgets.php');
        $upgrade = file_get_contents($root.'2026_07_14_000450_add_settings_snapshot_hash.php');
        $consumer = file_get_contents($root.'2026_07_14_000500_add_benchmark_execution_snapshot.php');

        self::assertIsString($historical);
        self::assertStringNotContainsString('snapshot_hash', $historical);
        self::assertIsString($upgrade);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS snapshot_hash char(64) NULL', $upgrade);
        self::assertStringContainsString('public $withinTransaction = false', $upgrade);
        self::assertStringContainsString("SET lock_timeout TO '2s'", $upgrade);
        self::assertStringNotContainsString('UPDATE estimate_generation_setting_snapshots', $upgrade);
        self::assertStringNotContainsString('LOCK TABLE estimate_generation_setting_snapshots', $upgrade);
        self::assertStringNotContainsString('DROP TRIGGER IF EXISTS eg_setting_snapshot_immutable', $upgrade);
        self::assertStringContainsString("data_type = 'character' AND character_maximum_length = 64", $upgrade);
        self::assertStringContainsString('NOT VALID', $upgrade);
        self::assertStringNotContainsString('VALIDATE CONSTRAINT eg_setting_snapshot_hash_ck', $upgrade);
        self::assertStringNotContainsString('ALTER COLUMN snapshot_hash SET NOT NULL', $upgrade);
        self::assertStringNotContainsString('DROP COLUMN IF EXISTS snapshot_hash', $upgrade);
        self::assertIsString($consumer);
        self::assertStringContainsString('settings_snapshot_hash', $consumer);
    }

    public function test_canonical_hash_upgrade_uses_only_resumable_side_table_backfill(): void
    {
        $root = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $compatibility = file_get_contents($root.'2026_07_14_000950_canonicalize_settings_snapshot_hashes.php');
        $sideTable = file_get_contents($root.'2026_07_14_001125_create_canonical_settings_snapshot_hashes.php');

        self::assertIsString($compatibility);
        self::assertStringNotContainsString('DROP TRIGGER', $compatibility);
        self::assertStringNotContainsString("->update([", $compatibility);
        self::assertIsString($sideTable);
        self::assertStringContainsString('public $withinTransaction = false', $sideTable);
        self::assertStringContainsString("SET lock_timeout TO '2s'", $sideTable);
        self::assertStringContainsString('chunkById(200', $sideTable);
        self::assertStringContainsString('insertOrIgnore', $sideTable);
        self::assertStringContainsString('canonical_settings_snapshot_hash_backfill_progress', $sideTable);
        self::assertStringContainsString('NOT VALID', $sideTable);
        self::assertStringNotContainsString('dropIfExists', $sideTable);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        $stages = ['vision', 'classification', 'planning', 'normative_matching', 'pricing'];

        return [
            'scope' => 'organization',
            'organization_id' => 17,
            'expected_version' => 3,
            'idempotency_key' => '01J2X5B8YWFK9YD8Q6V1VZ4H3K',
            'models' => array_fill_keys($stages, 'provider/model-v2'),
            'limits' => ['max_files' => 20, 'max_pages_per_file' => 500, 'max_total_pages' => 2000],
            'timeouts' => array_fill_keys($stages, 120),
            'retries' => array_fill_keys($stages, 2),
            'confidence' => ['classification' => '0.8000', 'geometry' => '0.7500', 'normative_matching' => '0.8500', 'pricing' => '0.9000'],
            'enabled_formats' => ['pdf', 'png', 'xlsx'],
            'manual_review' => ['low_confidence' => true, 'missing_evidence' => true, 'price_outlier' => true, 'normative_fallback' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }
}
