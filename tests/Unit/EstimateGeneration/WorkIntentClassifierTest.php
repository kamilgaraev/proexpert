<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkIntentClassifierTest extends TestCase
{
    #[DataProvider('workIntentProvider')]
    public function test_classifies_private_house_work_intents(
        string $name,
        string $unit,
        string $expectedScope,
        string $expectedAction,
        string $expectedDimension,
        array $forbiddenCollections
    ): void {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog()))->classify([
            'name' => $name,
            'unit' => $unit,
        ], [
            'scope_type' => $expectedScope,
        ]);

        $this->assertSame($expectedScope, $intent->scope);
        $this->assertSame($expectedAction, $intent->action);
        $this->assertContains($expectedDimension, $intent->expectedDimensions);

        foreach ($forbiddenCollections as $collection) {
            $this->assertContains($collection, $intent->forbiddenNormTypes);
        }
    }

    public function test_returns_non_empty_low_confidence_intent_for_unknown_work(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog()))->classify([
            'name' => 'Нестандартная работа по объекту',
            'unit' => 'компл',
        ], []);

        $this->assertNotSame('', $intent->scope);
        $this->assertNotSame('', $intent->action);
        $this->assertContains('set', $intent->expectedDimensions);
        $this->assertLessThan(0.6, $intent->confidence);
    }

    public static function workIntentProvider(): array
    {
        return [
            ['Утепление кровли 200 мм', 'м2', 'roof', 'insulation', 'area', ['gesn_earthwork']],
            ['Разводка труб отопления', 'м', 'engineering', 'pipe_layout', 'length', ['gesn_earthwork']],
            ['Прокладка кабельных линий', 'м', 'engineering', 'cable_installation', 'length', ['gesn_earthwork']],
            ['Кладка наружных стен из газобетона D500 400 мм', 'м3', 'walls', 'masonry', 'volume', ['gesn_earthwork']],
            ['Фасадная штукатурка по газобетону', 'м2', 'facade', 'plastering', 'area', ['gesn_earthwork']],
            ['Воздушно-тепловые завесы ворот', 'шт', 'engineering', 'heating_equipment', 'piece', ['gesn_earthwork']],
            ['Монтаж вентиляции', 'м2', 'engineering', 'ventilation_installation', 'area', ['gesn_earthwork']],
            ['Установка окон и дверей', 'шт', 'openings', 'window_installation', 'piece', ['gesn_earthwork']],
            ['Временное ограждение площадки', 'м', 'temporary', 'fence_installation', 'length', ['gesn_earthwork']],
            ['Опалубка ленточного фундамента', 'м2', 'foundation', 'formwork', 'area', []],
            ['Армирование фундаментной ленты', 'т', 'foundation', 'reinforcement', 'mass', []],
            ['Бетонирование фундаментной ленты B22.5', 'м3', 'foundation', 'concreting', 'volume', []],
        ];
    }
}
