<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use PHPUnit\Framework\TestCase;

final class NormativeCandidatePresenterTest extends TestCase
{
    public function test_presenter_returns_structured_privacy_safe_candidate_contract(): void
    {
        $payload = (new NormativeCandidatePresenter)->present([
            'norm_id' => 17,
            'code' => '06-01-001-01',
            'name' => 'Бетонирование фундамента',
            'unit' => 'м3',
            'collection' => ['code' => 'ГЭСН', 'name' => 'ГЭСН 2022', 'norm_type' => 'gesn'],
            'section' => ['id' => 9, 'code' => '06', 'name' => 'Бетонные работы', 'type' => 'section', 'path' => '06/01'],
            'confidence' => 0.91,
            'score' => 78.25,
            'match_reasons' => ['name_tokens_match'],
            'warnings' => ['unit_mismatch'],
            'learning_sources' => [[
                'example_id' => 991,
                'work_name' => 'Частный объект клиента',
                'source_type' => 'confirmed_feedback',
                'decision_status' => 'accepted',
                'normative_code' => '06-01-001-01',
                'is_positive' => true,
                'score' => 4.5,
            ]],
        ]);

        self::assertSame(['code' => 'ГЭСН', 'name' => 'ГЭСН 2022', 'norm_type' => 'gesn'], $payload['collection']);
        self::assertSame(['id' => 9, 'code' => '06', 'name' => 'Бетонные работы', 'type' => 'section', 'path' => '06/01'], $payload['section']);
        self::assertSame([[
            'source_type' => 'confirmed_feedback',
            'decision_status' => 'accepted',
            'normative_code' => '06-01-001-01',
            'is_positive' => true,
            'score' => 4.5,
        ]], $payload['learning_sources']);
        self::assertArrayNotHasKey('example_id', $payload['learning_sources'][0]);
        self::assertArrayNotHasKey('work_name', $payload['learning_sources'][0]);
        self::assertSame('retrieval_score', $payload['score_kind']);
        self::assertNull($payload['rerank']);
    }

    public function test_presenter_returns_scaled_price_preview_for_work_item_quantity(): void
    {
        $candidate = [
            'key' => 'norm-1',
            'norm_id' => 1,
            'code' => '01-01-001-01',
            'name' => 'Разработка грунта',
            'unit' => '1000 м3',
            'confidence' => 0.91,
            'score' => 92,
            'resources' => [
                'materials' => [[
                    'total_price' => 500000,
                    'price_source' => 'fsbc_base',
                ]],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
        ];

        $payload = (new NormativeCandidatePresenter)->present($candidate, [
            'unit' => 'м3',
            'quantity' => 500,
        ]);

        self::assertTrue($payload['preview_calculable']);
        self::assertSame(500.0, $payload['unit_price_preview']);
        self::assertSame(250000.0, $payload['total_cost_preview']);
        self::assertSame(500.0, $payload['cost_breakdown_preview']['materials']);
        self::assertArrayNotHasKey('work', $payload['cost_breakdown_preview']);
        self::assertSame(['fsbc_base'], $payload['price_sources']);
    }

    public function test_presenter_marks_candidate_with_partial_resource_prices(): void
    {
        $candidate = [
            'key' => 'norm-2',
            'norm_id' => 2,
            'code' => '06-01-001-01',
            'name' => 'Бетонирование фундамента',
            'unit' => 'м3',
            'confidence' => 0.91,
            'score' => 92,
            'resources' => [
                'materials' => [[
                    'total_price' => 5000,
                    'price_source' => 'fsbc_base',
                ], [
                    'total_price' => 0,
                    'price_source' => null,
                ]],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
        ];

        $payload = (new NormativeCandidatePresenter)->present($candidate, [
            'unit' => 'м3',
            'quantity' => 10,
        ]);

        self::assertFalse($payload['preview_calculable']);
        self::assertSame(2, $payload['resources_count']);
        self::assertSame(1, $payload['priced_resources_count']);
        self::assertSame(1, $payload['unpriced_resources_count']);
        self::assertNull($payload['unit_price_preview']);
        self::assertNull($payload['total_cost_preview']);
        self::assertNull($payload['cost_breakdown_preview']);
    }

    public function test_presenter_treats_zero_price_resource_as_unpriced(): void
    {
        $candidate = [
            'key' => 'norm-3',
            'norm_id' => 3,
            'code' => '06-01-002-01',
            'name' => 'Бетонирование ростверка',
            'unit' => 'м3',
            'confidence' => 0.91,
            'score' => 92,
            'resources' => [
                'materials' => [[
                    'quantity' => 1,
                    'unit_price' => 0,
                    'total_price' => 0,
                    'price_source' => 'fsbc_base',
                ]],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
        ];

        $payload = (new NormativeCandidatePresenter)->present($candidate, [
            'unit' => 'м3',
            'quantity' => 10,
        ]);

        self::assertFalse($payload['preview_calculable']);
        self::assertSame(0, $payload['priced_resources_count']);
        self::assertSame(1, $payload['unpriced_resources_count']);
        self::assertNull($payload['unit_price_preview']);
        self::assertSame([], $payload['price_sources']);
    }
}
