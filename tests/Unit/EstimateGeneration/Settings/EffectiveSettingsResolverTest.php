<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EffectiveSettingsResolverTest extends TestCase
{
    #[Test]
    public function it_pins_one_snapshot_to_the_whole_logical_operation(): void
    {
        $loads = 0;
        $snapshot = $this->record();
        $resolver = new EffectiveSettingsResolver(static function (int $organizationId) use (&$loads, $snapshot): array {
            $loads++;
            self::assertSame(17, $organizationId);

            return $snapshot;
        });

        $first = $resolver->forOperation('7df88f6f-648e-4c0d-8e84-d2587f51cde1', 17);
        $second = $resolver->forOperation('7df88f6f-648e-4c0d-8e84-d2587f51cde1', 17);

        self::assertSame($first, $second);
        self::assertSame(1, $loads);
    }

    /** @return array<string, mixed> */
    private function record(): array
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

        return ['snapshot_id' => 41, 'scope' => 'organization', 'organization_id' => 17, 'version' => 3,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot];
    }
}
