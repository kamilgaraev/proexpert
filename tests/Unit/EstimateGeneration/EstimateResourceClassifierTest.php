<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateResourceClassifier;
use Tests\TestCase;

class EstimateResourceClassifierTest extends TestCase
{
    public function test_classifier_detects_resource_types_by_code_and_name(): void
    {
        $classifier = app(EstimateResourceClassifier::class);

        $this->assertSame(EstimateResourceType::SUMMARY->value, $classifier->classify('1', '1'));
        $this->assertSame(EstimateResourceType::LABOR->value, $classifier->classify('1-100-40', 'Средний разряд работы 4,0'));
        $this->assertSame(EstimateResourceType::LABOR->value, $classifier->classify('3-200-01', 'Инженер I категории'));
        $this->assertSame(EstimateResourceType::MACHINE->value, $classifier->classify('91.05.13-021', 'Автомобили бортовые'));
        $this->assertSame(EstimateResourceType::MATERIAL->value, $classifier->classify('01.1.01.01-0002', 'Детали фасонные'));
        $this->assertSame(EstimateResourceType::EQUIPMENT->value, $classifier->classify(null, 'Шкаф управления'));
    }
}
