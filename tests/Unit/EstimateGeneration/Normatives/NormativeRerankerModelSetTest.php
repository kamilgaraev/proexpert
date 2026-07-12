<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NormativeRerankerModelSetTest extends TestCase
{
    public function test_order_is_bounded_deduplicated_and_versioned(): void
    {
        $set = new NormativeRerankerModelSet('openai/gpt-5-mini,openai/gpt-5-nano,openai/gpt-5-mini,timeweb/model-1,openai/model-2');
        self::assertSame(['openai/gpt-5-mini', 'openai/gpt-5-nano', 'timeweb/model-1', 'openai/model-2'], $set->models);
        self::assertStringStartsWith('models:', $set->version());
    }

    public function test_empty_model_set_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NormativeRerankerModelSet([]);
    }
}
