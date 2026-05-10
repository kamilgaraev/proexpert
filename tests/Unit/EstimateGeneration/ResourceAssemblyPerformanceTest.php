<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Tests\TestCase;

class ResourceAssemblyPerformanceTest extends TestCase
{
    public function test_normative_matching_is_cached_by_search_key(): void
    {
        $matcher = new class extends EstimateNormativeMatcher {
            public int $calls = 0;

            public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
            {
                $this->calls++;

                return null;
            }
        };

        $service = new ResourceAssemblyService(
            $matcher,
            app(NormativeMatchDecisionService::class),
            app(NormativeCandidatePresenter::class)
        );
        $items = [];

        for ($index = 1; $index <= 24; $index++) {
            $items[] = [
                'key' => 'item-' . $index,
                'name' => 'Бетонирование фундаментной ленты: детализация ' . $index,
                'work_category' => 'concrete',
                'unit' => 'м3',
                'quantity' => 1,
                'normative_search_key' => 'foundation|concrete|бетонирование фундаментной ленты|м3',
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'validation_flags' => [],
            ];
        }

        $service->enrich($items, ['scope_type' => 'foundation']);

        $this->assertSame(1, $matcher->calls);
    }

    public function test_enrichment_reports_progress_for_long_batches(): void
    {
        $matcher = new class extends EstimateNormativeMatcher {
            public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
            {
                return null;
            }
        };

        $service = new ResourceAssemblyService(
            $matcher,
            app(NormativeMatchDecisionService::class),
            app(NormativeCandidatePresenter::class)
        );
        $ticks = [];
        $items = [];

        for ($index = 1; $index <= 25; $index++) {
            $items[] = [
                'key' => 'item-' . $index,
                'name' => 'Работа ' . $index,
                'work_category' => 'custom',
                'unit' => 'ед.',
                'quantity' => 1,
                'normative_search_key' => 'custom|' . $index,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'validation_flags' => [],
            ];
        }

        $service->enrich($items, [
            'progress_callback' => static function (int $processed, int $total) use (&$ticks): void {
                $ticks[] = [$processed, $total];
            },
        ]);

        $this->assertContains([10, 25], $ticks);
        $this->assertContains([20, 25], $ticks);
        $this->assertContains([25, 25], $ticks);
    }
}
