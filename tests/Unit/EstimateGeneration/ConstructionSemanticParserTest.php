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

    public function test_parser_extracts_mixed_office_warehouse_prompt_as_separate_scopes(): void
    {
        $parser = new ConstructionSemanticParser();

        $analysis = $parser->parse([
            'description' => $this->mixedOfficeWarehousePrompt(),
            'building_type' => 'Производственное',
            'area' => 780,
        ], []);

        $structure = $analysis['detected_structure'];
        $constructives = $structure['constructives'];
        $titles = array_column($structure['scopes'], 'title');

        $this->assertContains('1 этаж', $structure['floors']);
        $this->assertContains('2 этаж', $structure['floors']);
        $this->assertContains('slabs', $constructives);
        $this->assertContains('openings', $constructives);
        $this->assertContains('facade', $constructives);
        $this->assertContains('roof', $constructives);
        $this->assertContains('heating', $constructives);
        $this->assertContains('ventilation', $constructives);
        $this->assertContains('electrical', $constructives);
        $this->assertContains('plumbing', $constructives);
        $this->assertContains('site', $constructives);
        $this->assertContains('fire_safety', $constructives);
        $this->assertContains('stairs', $constructives);
        $this->assertGreaterThanOrEqual(10, count($structure['scopes']));
        $this->assertContains('Входная группа', $structure['zones']);
        $this->assertContains('Пожарная сигнализация', $structure['zones']);
        $this->assertContains('Освещение', $structure['zones']);
        $this->assertContains('Водоснабжение и канализация', $structure['zones']);
        $this->assertNotContains('Еще нужна входная группа', $structure['zones']);
        $this->assertNotContains('водоснабжение', $structure['zones']);
        $this->assertNotContains('канализация', $structure['zones']);
        $this->assertNotContains(
            'Нужна входная группа, фасад из сэндвич-панелей, плоская кровля, отопление, вентиляция, электрика, водоснабжение, канализация, наружная площадка и подъезд для грузового транспорта.',
            $titles
        );
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

    private function mixedOfficeWarehousePrompt(): string
    {
        return <<<'TEXT'
Нужно сделать смету на небольшой двухэтажный офисно-складской корпус 780 м2 в Татарстане.
На первом этаже склад 420 м2 с промышленным бетонным полом, разгрузочной зоной, воротами, пожарной сигнализацией и освещением.
На втором этаже офисы 260 м2, переговорная, санузлы, серверная и лестничная клетка.
Нужна входная группа, фасад из сэндвич-панелей, плоская кровля, отопление, вентиляция, электрика, водоснабжение, канализация, наружная площадка и подъезд для грузового транспорта.
TEXT;
    }
}
