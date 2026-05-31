<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Tests\TestCase;

final class ResourceAssemblySafetyTest extends TestCase
{
    public function test_selected_norm_with_incompatible_unit_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'roof-insulation-1',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
            'confidence' => 0.7,
            'validation_flags' => [],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-07'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-07'],
            'selected' => $this->unsafeCandidate(),
            'candidates' => [$this->unsafeCandidate()],
        ];

        $service = new ResourceAssemblyService(
            new class ($match) extends EstimateNormativeMatcher {
                /**
                 * @param array<string, mixed> $match
                 */
                public function __construct(private readonly array $match) {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    return $this->match;
                }
            },
            app(NormativeMatchDecisionService::class),
            app(NormativeCandidatePresenter::class),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'roof'])[0];
        $item = app(EstimatePricingService::class)->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame([], $item['materials']);
        $this->assertSame([], $item['labor']);
        $this->assertSame([], $item['machinery']);
        $this->assertSame(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertContains('unit_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('requires_normative_review', $item['validation_flags']);
    }

    public function test_safe_review_priced_candidate_keeps_normative_resources_and_price(): void
    {
        $workItem = [
            'key' => 'roof-insulation-1',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 100,
            'confidence' => 0.7,
            'validation_flags' => ['normative_required'],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-31'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-31'],
            'selected' => $this->safeReviewCandidate(),
            'candidates' => [$this->safeReviewCandidate()],
        ];

        $service = new ResourceAssemblyService(
            new class ($match) extends EstimateNormativeMatcher {
                /**
                 * @param array<string, mixed> $match
                 */
                public function __construct(private readonly array $match) {}

                public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
                {
                    return $this->match;
                }
            },
            app(NormativeMatchDecisionService::class),
            app(NormativeCandidatePresenter::class),
        );

        $item = $service->enrich([$workItem], ['scope_type' => 'roof'])[0];
        $item = app(EstimatePricingService::class)->price([$item])[0];

        $this->assertSame('matched', $item['normative_match']['status']);
        $this->assertSame('review_priced', $item['normative_match']['decision']['status']);
        $this->assertSame('calculated_review_required', $item['pricing_status']);
        $this->assertGreaterThan(0, $item['total_cost']);
        $this->assertNotContains('safe_norm_required', $item['validation_flags']);
        $this->assertNotContains('pricing_not_calculated', $item['validation_flags']);
        $this->assertContains('safe_normative_analog', $item['validation_flags']);
    }

    /**
     * @return array<string, mixed>
     */
    private function unsafeCandidate(): array
    {
        return [
            'key' => 'norm-100',
            'norm_id' => 100,
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '01-01', 'name' => 'Земляные работы'],
            'score' => 90,
            'confidence' => 0.9,
            'match_reasons' => ['name'],
            'warnings' => [],
            'work_composition' => ['Разработка грунта'],
            'resources' => [
                'materials' => [[
                    'code' => '01.1.01.01-0001',
                    'name' => 'Песок',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 1000.0,
                    'total_price' => 1000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeReviewCandidate(): array
    {
        return [
            'key' => 'norm-120',
            'norm_id' => 120,
            'code' => '12-01-013-01',
            'name' => 'Утепление покрытий кровли минераловатными плитами',
            'unit' => 'м2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '12-01', 'name' => 'Кровли'],
            'score' => 64,
            'confidence' => 0.61,
            'match_reasons' => ['unit', 'search_profile_section'],
            'warnings' => [],
            'work_composition' => ['Укладка плит утеплителя'],
            'resources' => [
                'materials' => [[
                    'code' => '12.1.01.01-0001',
                    'name' => 'Плиты минераловатные',
                    'resource_type' => 'material',
                    'unit' => 'м2',
                    'quantity' => 1.05,
                    'unit_price' => 800.0,
                    'total_price' => 840.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }
}
