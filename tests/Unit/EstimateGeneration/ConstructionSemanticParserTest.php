<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use PHPUnit\Framework\TestCase;

class ConstructionSemanticParserTest extends TestCase
{
    public function test_parser_preserves_confirmed_object_dimensions_and_construction_type(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'description' => 'Дом по эскизу',
            'construction_type' => 'new_construction',
            'floors' => 2,
            'height' => 3.2,
        ], []);

        $this->assertSame('new_construction', $analysis['object']['construction_type']);
        $this->assertSame(2, $analysis['object']['floors']);
        $this->assertSame(3.2, $analysis['object']['height']);
    }

    public function test_parser_detects_house_prompt_without_fake_sheet(): void
    {
        $parser = new ConstructionSemanticParser;

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
        $parser = new ConstructionSemanticParser;

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

    public function test_parser_uses_trusted_ocr_facts_when_manual_description_is_empty(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => '',
        ], [[
            'id' => 77,
            'filename' => 'warehouse-plan.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.91, 'flags' => []],
            'extracted_text' => 'Складской корпус 1280 м2. Склад 900 м2. Офис 280 м2. 1 этаж. Пожарная сигнализация, освещение.',
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'quantity_source',
                ],
                'total_area_m2' => 1280.0,
                'floor_count' => 1.0,
                'zones' => [
                    ['scope_key' => 'warehouse_area', 'label' => 'Склад', 'area_m2' => 900.0],
                    ['scope_key' => 'office_area', 'label' => 'Офис', 'area_m2' => 280.0],
                ],
                'engineering_systems' => [
                    ['key' => 'fire_alarm', 'label' => 'Пожарная сигнализация'],
                    ['key' => 'lighting', 'label' => 'Освещение'],
                ],
                'conflicts' => [],
            ],
            'facts' => [[
                'fact_type' => 'total_area',
                'scope_key' => 'total_area',
                'label' => 'Общая площадь',
                'value_number' => 1280.0,
                'source_ref' => [
                    'type' => 'document',
                    'document_id' => 77,
                    'filename' => 'warehouse-plan.pdf',
                    'page_number' => 1,
                    'excerpt' => 'Складской корпус 1280 м2',
                ],
            ]],
        ]]);

        $this->assertSame('', $analysis['object']['manual_description']);
        $this->assertSame(1280.0, $analysis['object']['area']);
        $this->assertSame(1.0, $analysis['object']['floors']);
        $this->assertSame('mixed_warehouse_office', $analysis['object']['object_type']);
        $this->assertSame(['fire_alarm', 'lighting'], $analysis['object']['engineering_systems']);
        $this->assertSame(77, $analysis['document_context']['source_refs'][0]['document_id']);
        $this->assertSame([], $analysis['problem_flags']);
    }

    public function test_parser_keeps_explicit_house_type_when_document_mentions_storage_process(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => 'Хочу получить подробную смету на частный дом',
            'building_type' => 'Жилой',
        ], [[
            'id' => 79,
            'filename' => 'house-plan.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.95, 'flags' => []],
            'extracted_text' => implode("\n", [
                'Индивидуальный жилой дом',
                'Обратную засыпку пазух котлована выполнять с послойным уплотнением грунта.',
                'Временное складирование материалов допускается только на строительной площадке.',
            ]),
            'facts_summary' => [
                'total_area_m2' => 151.76,
                'floor_count' => 1.0,
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
        ]]);
        $scopeTitles = array_column($analysis['detected_structure']['scopes'], 'title');
        $scopeTypes = array_column($analysis['detected_structure']['scopes'], 'scope_type');

        $this->assertSame('Жилой', $analysis['object']['building_type']);
        $this->assertSame('house', $analysis['object']['object_type']);
        $this->assertNotContains('Промышленный пол', $scopeTitles);
        $this->assertNotContains('Металлокаркас', $scopeTitles);
        $this->assertNotContains('structural', $scopeTypes);
    }

    public function test_parser_prioritizes_manual_residential_type_over_incidental_document_object_terms(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'description' => 'Индивидуальный жилой дом площадью 180 м2.',
        ], [[
            'id' => 80,
            'filename' => 'house-specification.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.95, 'flags' => []],
            'extracted_text' => 'Служебные обозначения: офис заказчика, склад материалов.',
            'facts_summary' => [
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
        ]]);

        $this->assertSame('house', $analysis['object']['object_type']);
    }

    public function test_parser_preserves_manual_mixed_office_warehouse_type(): void
    {
        $analysis = (new ConstructionSemanticParser)->parse([
            'description' => 'Офисно-складской комплекс с отдельными зонами офиса и склада.',
        ], []);

        $this->assertSame('mixed_warehouse_office', $analysis['object']['object_type']);
    }

    public function test_parser_uses_aggregate_floor_plan_area_instead_of_first_room(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => '',
        ], [[
            'id' => 80,
            'filename' => 'flat-plan.png',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.91, 'flags' => []],
            'extracted_text' => "Планировка квартиры\nГостиная 46,52 м²\nКухня 9,99 м2",
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'geometry_source',
                ],
                'drawing_understanding' => [
                    'room_area_total_m2' => 56.51,
                ],
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
            'quantity_takeoffs' => [
                [
                    'scope_key' => 'room_area',
                    'quantity' => 46.52,
                    'unit' => 'м2',
                ],
                [
                    'scope_key' => 'room_area',
                    'quantity' => 9.99,
                    'unit' => 'м2',
                ],
                [
                    'scope_key' => 'floor_finish_area',
                    'quantity' => 56.51,
                    'unit' => 'м2',
                    'normalized_payload' => ['quantity_key' => 'finish.floor'],
                ],
            ],
        ]]);

        $this->assertSame(56.51, $analysis['object']['area']);
        $this->assertSame(56.51, $analysis['document_context']['facts_summary']['total_area_m2']);
    }

    public function test_parser_does_not_use_reference_estimate_as_primary_quantity_evidence(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => '',
        ], [[
            'id' => 81,
            'filename' => 'grand-smeta-reference.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.95, 'flags' => []],
            'extracted_text' => "Локальная смета\nГранд-Смета\nКровля 999 м2\nЭлектрика",
            'facts_summary' => [
                'total_area_m2' => 999.0,
                'document_understanding' => [
                    'role_for_estimation' => 'reference_estimate',
                    'document_type' => 'estimate',
                ],
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
            'quantity_takeoffs' => [[
                'scope_key' => 'floor_finish_area',
                'quantity' => 999.0,
                'unit' => 'м2',
                'normalized_payload' => ['quantity_key' => 'finish.floor'],
            ]],
        ]]);

        $this->assertNull($analysis['object']['area']);
        $this->assertSame('', $analysis['document_context']['context_text']);
        $this->assertSame([], $analysis['document_context']['quantity_takeoffs']);
        $this->assertSame(81, $analysis['document_context']['non_primary_documents'][0]['id']);
        $this->assertSame('reference_estimate', $analysis['document_context']['non_primary_documents'][0]['document_role']);
    }

    public function test_parser_keeps_context_document_text_without_quantity_evidence(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => '',
        ], [[
            'id' => 82,
            'filename' => 'technical-note.pdf',
            'status' => 'ready',
            'quality' => ['level' => 'good', 'score' => 0.95, 'flags' => []],
            'extracted_text' => 'Technical description. Total area 999 m2. Office zone 500 m2.',
            'facts_summary' => [
                'total_area_m2' => 999.0,
                'document_understanding' => [
                    'role_for_estimation' => 'context_document',
                    'document_type' => 'technical_document',
                ],
                'drawing_understanding' => [
                    'room_area_total_m2' => 999.0,
                ],
                'zones' => [
                    ['scope_key' => 'office_area', 'label' => 'Office', 'area_m2' => 500.0],
                ],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
            'quantity_takeoffs' => [[
                'scope_key' => 'floor_finish_area',
                'quantity' => 999.0,
                'unit' => 'm2',
                'normalized_payload' => ['quantity_key' => 'finish.floor'],
            ]],
        ]]);

        $this->assertNull($analysis['object']['area']);
        $this->assertSame('Technical description. Total area 999 m2. Office zone 500 m2.', $analysis['document_context']['context_text']);
        $this->assertNull($analysis['document_context']['facts_summary']['total_area_m2']);
        $this->assertSame([], $analysis['document_context']['facts_summary']['zones']);
        $this->assertSame([], $analysis['document_context']['quantity_takeoffs']);
    }

    public function test_parser_does_not_trust_low_quality_ocr_for_object_defaults(): void
    {
        $parser = new ConstructionSemanticParser;

        $analysis = $parser->parse([
            'description' => 'Ручное описание объекта',
            'area' => 180,
        ], [[
            'id' => 78,
            'filename' => 'bad-scan.pdf',
            'status' => 'needs_review',
            'quality' => ['level' => 'low', 'score' => 0.31, 'flags' => ['low_quality']],
            'extracted_text' => 'Склад 5000 м2',
            'facts_summary' => [
                'total_area_m2' => 5000.0,
                'floor_count' => 1.0,
                'zones' => [],
                'engineering_systems' => [],
                'conflicts' => [],
            ],
            'facts' => [],
        ]]);

        $this->assertSame(180, $analysis['object']['area']);
        $this->assertSame('', $analysis['document_context']['context_text']);
        $this->assertContains('document_review_required', $analysis['problem_flags']);
        $this->assertSame(78, $analysis['document_context']['review_required_documents'][0]['id']);
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
