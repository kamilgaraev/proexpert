<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectiveEstimateGenerationSettingsTest extends TestCase
{
    #[Test]
    public function it_exposes_an_immutable_tenant_snapshot_for_runtime_stages(): void
    {
        $settings = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 41,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($this->snapshot()),
            'snapshot' => $this->snapshot(),
        ], 17);

        self::assertSame(41, $settings->snapshotId);
        self::assertSame('timeweb/vision-v2', $settings->model('vision'));
        self::assertSame(45, $settings->timeoutSeconds('vision'));
        self::assertSame(2, $settings->retryAttempts('vision'));
        self::assertSame('0.7800', $settings->confidence('geometry'));
        self::assertTrue($settings->allowsFormat('pdf'));
        self::assertSame(8, $settings->maxFiles());
        self::assertSame('250.00', $settings->dailyBudget());
    }

    #[Test]
    public function it_rejects_cross_tenant_snapshots(): void
    {
        $record = [
            'snapshot_id' => 41,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => str_repeat('0', 64),
            'snapshot' => $this->snapshot(),
        ];

        $this->expectException(DomainException::class);
        EffectiveEstimateGenerationSettings::fromRecord($record, 18);
    }

    #[Test]
    public function it_rejects_tampered_snapshot_content_for_the_same_tenant(): void
    {
        $snapshot = $this->snapshot();
        $record = [
            'snapshot_id' => 41,
            'scope' => 'organization',
            'organization_id' => 17,
            'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot),
            'snapshot' => [...$snapshot, 'limits' => [...$snapshot['limits'], 'max_files' => 99]],
        ];

        $this->expectException(DomainException::class);
        EffectiveEstimateGenerationSettings::fromRecord($record, 17);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'schema_version' => 2,
            'models' => ['vision' => 'timeweb/vision-v2', 'classification' => 'timeweb/classify-v1', 'normative_matching' => 'timeweb/rerank-v1'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 45, 'classification' => 30, 'normative_matching' => 20],
            'retries' => ['vision' => 2, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200'],
            'enabled_formats' => ['pdf', 'jpg', 'png', 'dxf', 'dwg', 'xlsx'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ];
    }
}
