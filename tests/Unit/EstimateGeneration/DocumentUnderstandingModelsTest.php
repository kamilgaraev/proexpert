<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationScopeInference;
use PHPUnit\Framework\TestCase;

final class DocumentUnderstandingModelsTest extends TestCase
{
    public function test_drawing_element_model_uses_understanding_table_and_json_casts(): void
    {
        $model = new EstimateGenerationDrawingElement();

        self::assertSame('estimate_generation_drawing_elements', $model->getTable());
        self::assertContains('source_ref', $model->getFillable());
        self::assertSame('array', $model->getCasts()['bbox'] ?? null);
        self::assertSame('array', $model->getCasts()['geometry'] ?? null);
        self::assertSame('array', $model->getCasts()['source_ref'] ?? null);
        self::assertSame('array', $model->getCasts()['normalized_payload'] ?? null);
    }

    public function test_quantity_takeoff_model_uses_understanding_table_and_json_casts(): void
    {
        $model = new EstimateGenerationQuantityTakeoff();

        self::assertSame('estimate_generation_quantity_takeoffs', $model->getTable());
        self::assertContains('work_intent', $model->getFillable());
        self::assertSame('array', $model->getCasts()['source_element_ids'] ?? null);
        self::assertSame('array', $model->getCasts()['work_intent'] ?? null);
        self::assertSame('array', $model->getCasts()['source_refs'] ?? null);
        self::assertSame('array', $model->getCasts()['normalized_payload'] ?? null);
    }

    public function test_scope_inference_model_uses_understanding_table_and_review_casts(): void
    {
        $model = new EstimateGenerationScopeInference();

        self::assertSame('estimate_generation_scope_inferences', $model->getTable());
        self::assertContains('normative_basis', $model->getFillable());
        self::assertSame('array', $model->getCasts()['source_refs'] ?? null);
        self::assertSame('array', $model->getCasts()['normative_basis'] ?? null);
        self::assertSame('array', $model->getCasts()['work_intent'] ?? null);
        self::assertSame('boolean', $model->getCasts()['review_required'] ?? null);
        self::assertSame('datetime', $model->getCasts()['accepted_at'] ?? null);
    }
}
