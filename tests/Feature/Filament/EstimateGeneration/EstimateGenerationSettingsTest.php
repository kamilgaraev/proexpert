<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsData;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\Filament\Pages\EstimateGeneration\EstimateGenerationSettings;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationSettingsTest extends TestCase
{
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
