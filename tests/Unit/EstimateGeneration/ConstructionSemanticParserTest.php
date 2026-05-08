<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use Tests\TestCase;

class ConstructionSemanticParserTest extends TestCase
{
    public function test_parser_detects_house_prompt_without_fake_sheet(): void
    {
        $parser = new ConstructionSemanticParser();

        $analysis = $parser->parse([
            'description' => $this->housePrompt(),
            'building_type' => 'Жилой',
            'area' => 150,
        ], []);

        $this->assertSame(150, $analysis['object']['area']);
        $this->assertSame('Московская область', $analysis['object']['region']);
        $this->assertSame(2026, $analysis['object']['year']);
        $this->assertSame(1, $analysis['object']['quarter']);
        $this->assertSame(10.0, $analysis['object']['contingency_percent']);
        $this->assertSame([], $analysis['detected_structure']['sheets']);

        $constructives = $analysis['detected_structure']['constructives'];
        $this->assertContains('foundation', $constructives);
        $this->assertContains('walls', $constructives);
        $this->assertContains('slabs', $constructives);
        $this->assertContains('roof', $constructives);
        $this->assertContains('openings', $constructives);
        $this->assertContains('electrical', $constructives);
        $this->assertContains('plumbing', $constructives);
        $this->assertContains('heating', $constructives);
        $this->assertContains('ventilation', $constructives);
        $this->assertContains('rough_finishing', $constructives);
        $this->assertContains('finish_finishing', $constructives);
    }

    private function housePrompt(): string
    {
        return <<<'TEXT'
Составь подробную строительную смету на одноэтажный жилой дом общей площадью 150 м². Укажи средние цены для Центрального региона РФ (Московская область) в рублях за первый квартал 2026 года.

Фундамент (мелкозаглубленный ленточный, бетон В22.5, арматура).
*Стены и перегородки (газобетон D500 толщиной 400 мм + облицовочный кирпич или штукатурка).*
Перекрытия (монолитное или пустотные плиты).
Кровля (двускатная, металлочерепица, утеплитель 200 мм).
Окна и двери (двухкамерный стеклопакет, входная металлическая дверь).
Электрика (щит, кабель, розетки, свет)
Водопровод и канализация (колодец/скважина, септик, трубы)
Отопление (газовый котел, радиаторы, разводка)
Вентиляция (естественная + приточные клапаны)
Черновая отделка (стяжка пола, штукатурка стен).
Чистовая отделка (бюджетная: ламинат, плитка в санузлах, обои под покраску).
Добавь непредвиденные расходы 10%.
TEXT;
    }
}
