<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProcessingPlan;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiWireNotStarted;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrWireReservationTokens;
use PHPUnit\Framework\TestCase;

final class OcrWireReservationTokensTest extends TestCase
{
    public function test_realistic_encoded_media_does_not_become_text_tokens_or_exceed_the_approved_role(): void
    {
        $path = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/ocr/scanned-plan.pdf';
        $media = file_get_contents($path);
        self::assertIsString($media);
        self::assertGreaterThan(300000, strlen($media));
        $messages = [['role' => 'system', 'content' => 'Read the supplied document and return structured facts.'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Document page'],
                ['type' => 'input_file', 'filename' => 'scanned-plan.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode($media)]]]];
        $price = AiPriceSnapshot::fromArray(['currency' => 'RUB', 'source' => 'fixture', 'version' => 'luna-price-v2',
            'effective_at' => '2026-09-29T00:00:00+00:00', 'input_per_million' => '13.5', 'cached_input_per_million' => '13.5',
            'output_per_million' => '67.5', 'reasoning_per_million' => '67.5', 'reasoning_mode' => 'included_in_output', 'image_unit' => '0', 'page_unit' => '0']);
        $previous = \Illuminate\Container\Container::getInstance();
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository(['estimate-generation' => ['vision' => ['max_input_tokens' => 32768]]]));
        \Illuminate\Container\Container::setInstance($container);
        try {
            $plan = (new EstimatorProcessingPlan)->build('documents', 1, 0, false, $price);
        } finally {
            \Illuminate\Container\Container::setInstance($previous);
        }
        $upper = OcrWireReservationTokens::inputUpper($messages, 32768);
        $cost = (new AiCostCalculator)->calculate($upper, 0, 4096, 0, 0, 1, $price->toArray());
        self::assertSame(33792, $upper);
        self::assertSame('0.73267200', $cost->amount);
        self::assertSame(732672, $plan['roles']['ocr']['maximum_cost_micro_rub']);
        $oldCost = (new AiCostCalculator)->calculate(strlen(json_encode($messages, JSON_THROW_ON_ERROR)) + 1024,
            0, 4096, 0, 0, 1, $price->toArray());
        self::assertTrue(\Brick\Math\BigDecimal::of($oldCost->amount)->isGreaterThan($cost->amount));
    }

    public function test_large_text_requires_chunking_before_any_physical_attempt(): void
    {
        $this->expectException(AiWireNotStarted::class);
        $this->expectExceptionMessage('ocr_input_requires_chunking');
        OcrWireReservationTokens::inputUpper([['role' => 'system', 'content' => str_repeat('x', 32769)]], 32768);
    }
}
