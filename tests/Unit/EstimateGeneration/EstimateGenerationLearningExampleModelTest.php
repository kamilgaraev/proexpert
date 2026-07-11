<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class EstimateGenerationLearningExampleModelTest extends TestCase
{
    public function test_model_casts_learning_payloads_and_dates(): void
    {
        $example = new EstimateGenerationLearningExample;
        $example->forceFill([
            'context_payload' => ['section' => 'Фундамент'],
            'work_intent' => ['scope' => 'foundation', 'action' => 'concreting'],
            'source_refs' => [['type' => 'estimate_item', 'id' => 15]],
            'quality_flags' => ['unit_checked', 'price_checked'],
            'accepted_at' => '2026-05-30 12:00:00',
            'indexed_at' => '2026-05-30 13:00:00',
        ]);

        $this->assertSame(['section' => 'Фундамент'], $example->context_payload);
        $this->assertSame(['scope' => 'foundation', 'action' => 'concreting'], $example->work_intent);
        $this->assertSame([['type' => 'estimate_item', 'id' => 15]], $example->source_refs);
        $this->assertSame(['unit_checked', 'price_checked'], $example->quality_flags);
        $this->assertInstanceOf(Carbon::class, $example->accepted_at);
        $this->assertInstanceOf(Carbon::class, $example->indexed_at);
    }

    public function test_model_exposes_expected_belongs_to_relations(): void
    {
        $example = new EstimateGenerationLearningExample;

        $this->assertInstanceOf(BelongsTo::class, $example->organization());
        $this->assertInstanceOf(BelongsTo::class, $example->project());
        $this->assertInstanceOf(BelongsTo::class, $example->generationSession());
        $this->assertInstanceOf(BelongsTo::class, $example->generationPackageItem());
        $this->assertInstanceOf(BelongsTo::class, $example->datasetVersion());
        $this->assertInstanceOf(BelongsTo::class, $example->estimateNorm());
        $this->assertContains('estimate_id', $example->getFillable());
        $this->assertContains('estimate_item_id', $example->getFillable());
    }
}
