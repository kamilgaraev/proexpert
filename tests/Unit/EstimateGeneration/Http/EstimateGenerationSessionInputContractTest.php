<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationSessionInputData;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationConstructionType;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationSessionInputContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function object_input_is_canonical_typed_and_versioned(): void
    {
        $input = EstimateGenerationSessionInputData::fromValidated([
            'description' => 'Дом по эскизу',
            'building_type' => 'residential',
            'generation_mode' => 'ai_assisted',
            'region' => 'Москва',
            'construction_type' => 'new_construction',
            'area' => 146.5,
            'floors' => 2,
            'height' => 3.2,
            'period_id' => 17,
        ]);

        self::assertSame([
            'schema_version' => 1,
            'description' => 'Дом по эскизу',
            'building_type' => 'residential',
            'generation_mode' => 'ai_assisted',
            'region' => 'Москва',
            'construction_type' => 'new_construction',
            'area' => 146.5,
            'floors' => 2,
            'height' => 3.2,
            'period_id' => 17,
            'estimate_regional_price_version_id' => null,
            'region_id' => null,
            'price_zone_id' => null,
            'normative_dataset_version' => null,
            'normative_rerank_requested' => false,
            'parameters' => [],
        ], $input->toArray());
    }

    #[Test]
    public function request_rejects_unknown_construction_type_and_invalid_dimensions(): void
    {
        $rules = (new CreateEstimateGenerationSessionRequest)->rules();

        self::assertFalse(Validator::make([
            'construction_type' => 'whatever',
            'floors' => 0,
            'height' => -1,
        ], $rules)->passes());
        self::assertTrue(Validator::make([
            'construction_type' => EstimateGenerationConstructionType::CapitalRepair->value,
            'floors' => 12,
            'height' => 3.1,
        ], $rules)->passes());
    }

    #[Test]
    public function construction_types_are_closed_and_have_russian_labels(): void
    {
        self::assertSame([
            'new_construction',
            'reconstruction',
            'capital_repair',
            'current_repair',
        ], EstimateGenerationConstructionType::values());
        self::assertSame('Капитальный ремонт', EstimateGenerationConstructionType::CapitalRepair->label());
    }
}
