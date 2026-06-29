<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateManualSearchServiceTest extends TestCase
{
    public function test_searches_same_normative_catalog_as_ai_matching_with_work_item_context(): void
    {
        $matcher = new FakeManualSearchMatcher();
        $service = new NormativeCandidateManualSearchService($matcher, new NormativeCandidatePresenter());
        $session = new EstimateGenerationSession([
            'id' => 15,
            'input_payload' => [
                'regional_context' => [
                    'estimate_regional_price_version_id' => 9,
                ],
            ],
            'draft_payload' => [
                'local_estimates' => [[
                    'key' => 'local-foundation',
                    'title' => 'Фундамент',
                    'scope_type' => 'foundation',
                    'sections' => [[
                        'key' => 'section-earth',
                        'title' => 'Земляные работы',
                        'source_refs' => [['type' => 'drawing', 'filename' => 'plan.pdf']],
                        'work_items' => [[
                            'key' => 'earth.backfill',
                            'item_type' => 'priced_work',
                            'name' => 'Обратная засыпка пазух',
                            'description' => '',
                            'work_category' => 'earthworks',
                            'unit' => 'м3',
                            'quantity' => 42.0,
                        ]],
                    ]],
                ]],
            ],
        ]);

        $result = $service->search($session, 'earth.backfill', '01-02-057', 7);

        self::assertSame('01-02-057', $matcher->workItem['normative_search_text']);
        self::assertSame('foundation', $matcher->context['scope_type']);
        self::assertSame('Фундамент', $matcher->context['local_estimate_title']);
        self::assertSame('Земляные работы', $matcher->context['section_title']);
        self::assertSame(9, $matcher->context['regional_context']['estimate_regional_price_version_id']);
        self::assertSame(7, $matcher->limit);
        self::assertSame([
            [
                'key' => 'norm-101',
                'norm_id' => 101,
                'code' => '01-02-057-01',
                'name' => 'Обратная засыпка грунта',
                'unit' => 'м3',
                'collection' => null,
                'section' => null,
                'confidence' => 0.91,
                'score' => 84.0,
                'resources_count' => 1,
                'priced_resources_count' => 1,
                'match_reasons' => ['exact_code'],
                'warnings' => [],
                'work_composition' => ['Засыпка грунта'],
                'learning_positive_count' => 0,
                'learning_negative_count' => 0,
                'learning_score' => 0.0,
                'learning_sources' => [],
            ],
        ], $result['candidates']);
    }
}

final class FakeManualSearchMatcher extends EstimateNormativeMatcher
{
    /** @var array<string, mixed> */
    public array $workItem = [];

    /** @var array<string, mixed> */
    public array $context = [];

    public int $limit = 0;

    public function __construct() {}

    public function matchWorkItem(array $workItem, array $context = [], int $limit = 5): ?array
    {
        $this->workItem = $workItem;
        $this->context = $context;
        $this->limit = $limit;

        return [
            'candidates' => [[
                'key' => 'norm-101',
                'norm_id' => 101,
                'code' => '01-02-057-01',
                'name' => 'Обратная засыпка грунта',
                'unit' => 'м3',
                'collection' => null,
                'section' => null,
                'confidence' => 0.91,
                'score' => 84.0,
                'resources' => [
                    'materials' => [[
                        'price_source' => 'fsbc_base',
                    ]],
                    'machinery' => [],
                    'labor' => [],
                    'other' => [],
                ],
                'match_reasons' => ['exact_code'],
                'warnings' => [],
                'work_composition' => ['Засыпка грунта'],
            ]],
        ];
    }
}
