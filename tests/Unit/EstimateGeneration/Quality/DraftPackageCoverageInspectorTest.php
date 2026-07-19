<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftPackageCoverageInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DraftPackageCoverageInspectorTest extends TestCase
{
    #[Test]
    public function it_reports_required_packages_that_were_silently_left_empty(): void
    {
        $missing = (new DraftPackageCoverageInspector)->missingPackages([
            'package_plan' => ['packages' => [
                ['key' => 'walls', 'title' => 'Стены', 'coverage_required' => true],
                ['key' => 'openings', 'title' => 'Окна и двери', 'coverage_required' => true],
                ['key' => 'siteworks', 'title' => 'Благоустройство', 'coverage_required' => false],
            ]],
            'local_estimates' => [
                $this->localEstimate('walls', [['item_type' => 'priced_work']]),
                $this->localEstimate('openings', []),
                $this->localEstimate('siteworks', []),
            ],
        ]);

        self::assertSame([[
            'key' => 'openings',
            'title' => 'Окна и двери',
        ]], $missing);
    }

    #[Test]
    public function explicit_quantity_review_item_makes_an_unpriced_scope_visible(): void
    {
        $missing = (new DraftPackageCoverageInspector)->missingPackages([
            'package_plan' => ['packages' => [
                ['key' => 'electrical', 'title' => 'Электрика', 'coverage_required' => true],
            ]],
            'local_estimates' => [
                $this->localEstimate('electrical', [[
                    'item_type' => 'quantity_review',
                    'validation_flags' => ['quantity_review_required'],
                ]]),
            ],
        ]);

        self::assertSame([], $missing);
    }

    #[Test]
    public function complete_house_draft_is_not_blocked_by_undocumented_preconstruction_scope(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'house',
            area: 180.0,
            floors: 2,
            rooms: 15,
            regionCode: 'RU-MOS',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q2',
            dimensions: [],
            finishLevels: ['rough', 'finish'],
            engineeringSystems: ['electrical', 'plumbing', 'heating', 'ventilation'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.86,
        );
        $packages = (new PackagePlannerService)->plan($profile)->packages;
        $localEstimates = array_map(
            fn (array $package): array => $this->localEstimate(
                (string) $package['key'],
                ($package['coverage_required'] ?? false) === true ? [['item_type' => 'priced_work']] : [],
            ),
            $packages,
        );

        $missing = (new DraftPackageCoverageInspector)->missingPackages([
            'package_plan' => ['packages' => $packages],
            'local_estimates' => $localEstimates,
        ]);

        self::assertSame([], $missing);
        self::assertFalse((bool) array_values(array_filter(
            $packages,
            static fn (array $package): bool => ($package['key'] ?? null) === 'preconstruction',
        ))[0]['coverage_required']);
    }

    private function localEstimate(string $key, array $workItems): array
    {
        return [
            'key' => $key,
            'sections' => [[
                'work_items' => $workItems,
            ]],
        ];
    }
}
