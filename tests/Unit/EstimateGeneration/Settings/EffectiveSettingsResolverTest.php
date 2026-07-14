<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectiveSettingsResolverTest extends TestCase
{
    #[Test]
    public function it_pins_one_snapshot_to_the_whole_logical_operation(): void
    {
        $loads = 0;
        $global = EffectiveEstimateGenerationSettings::fromRecord($this->record('global'), 17);
        $effective = EffectiveEstimateGenerationSettings::fromRecord($this->record('organization'), 17);
        $store = new class($loads, $global, $effective) implements EffectiveSettingsOperationStore
        {
            public function __construct(
                private int &$loads,
                private readonly EffectiveEstimateGenerationSettings $global,
                private readonly EffectiveEstimateGenerationSettings $effective,
            ) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                $this->loads++;
                Assert::assertSame('7df88f6f-648e-4c0d-8e84-d2587f51cde1', $correlationId);
                Assert::assertSame(17, $organizationId);
                Assert::assertSame(91, $sessionId);

                return new EffectiveSettingsPair($this->global, $this->effective);
            }
        };
        $resolver = new EffectiveSettingsResolver($store);

        $first = $resolver->forOperation('7df88f6f-648e-4c0d-8e84-d2587f51cde1', 17, 91);
        $second = $resolver->forOperation('7df88f6f-648e-4c0d-8e84-d2587f51cde1', 17, 91);

        self::assertSame($first, $second);
        self::assertSame(1, $loads);

        $afterRestart = (new EffectiveSettingsResolver($store))
            ->forOperation('7df88f6f-648e-4c0d-8e84-d2587f51cde1', 17, 91);

        self::assertSame($first->snapshotId, $afterRestart->snapshotId);
        self::assertSame(2, $loads);
    }

    /** @return array<string, mixed> */
    private function record(string $scope): array
    {
        $snapshot = [
            'schema_version' => 1,
            'models' => ['vision' => 'timeweb/vision-v2', 'classification' => 'timeweb/classify-v1', 'planning' => 'timeweb/plan-v1', 'normative_matching' => 'timeweb/rerank-v1', 'pricing' => 'timeweb/pricing-v1'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 45, 'classification' => 30, 'planning' => 30, 'normative_matching' => 20, 'pricing' => 20],
            'retries' => ['vision' => 2, 'classification' => 1, 'planning' => 1, 'normative_matching' => 2, 'pricing' => 1],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200', 'pricing' => '0.9000'],
            'enabled_formats' => ['pdf'],
            'manual_review' => ['low_confidence' => true, 'missing_evidence' => true, 'price_outlier' => true, 'normative_fallback' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ];

        return ['snapshot_id' => $scope === 'global' ? 40 : 41, 'scope' => $scope,
            'organization_id' => $scope === 'global' ? null : 17, 'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot];
    }
}
