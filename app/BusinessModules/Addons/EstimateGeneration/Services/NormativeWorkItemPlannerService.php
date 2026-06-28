<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class NormativeWorkItemPlannerService
{
    public function __construct(
        private readonly ProjectDocumentNormativeReferenceExtractor $projectDocumentNormativeReferenceExtractor,
        private readonly EstimatorScopeInferenceService $scopeInferenceService,
    ) {}

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    public function build(array $localEstimate, array $section, array $analysis): array
    {
        $quantityModel = $this->documentQuantityModel($analysis);
        $items = [];

        foreach ($this->projectDocumentNormativeReferenceExtractor->extract($analysis, $localEstimate, $section) as $referenceIndex => $reference) {
            $items[] = $this->workItemFromProjectReference($reference, $localEstimate, $section, $referenceIndex);
        }

        foreach ($this->definitions($localEstimate, $section, $analysis, $quantityModel) as $index => $definition) {
            $items[] = $this->workItemFromDefinition($definition, $localEstimate, $section, $analysis, $quantityModel, $index);
        }

        $items = $this->uniquePricedItems($items);

        return $this->withOperationRows($items, $localEstimate, $section);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function workItemFromProjectReference(array $reference, array $localEstimate, array $section, int $index): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $key = $packageKey . '-project-ref-' . ($index + 1);

        return $this->basePricedWorkItem(
            key: $key,
            localEstimate: $localEstimate,
            section: $section,
            name: (string) $reference['name'],
            searchText: (string) ($reference['normative_search_text'] ?? $reference['name']),
            category: (string) $reference['work_category'],
            unit: (string) $reference['unit'],
            quantity: (float) $reference['quantity'],
            quantityFormula: (string) $reference['quantity_formula'],
            quantityBasis: (string) $reference['quantity_basis'],
            sourceRefs: $reference['source_refs'] ?? [],
            confidence: (float) $reference['confidence'],
            validationFlags: $reference['validation_flags'] ?? ['normative_required'],
            metadata: $reference['metadata'] ?? [],
            normativeRateCode: isset($reference['normative_rate_code']) ? (string) $reference['normative_rate_code'] : null,
            operations: $this->operationBank((string) $reference['work_category'])
        );
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $quantityModel
     * @return array<string, mixed>
     */
    private function workItemFromDefinition(
        array $definition,
        array $localEstimate,
        array $section,
        array $analysis,
        array $quantityModel,
        int $index
    ): array {
        $quantity = $this->quantityForDefinition($definition, $analysis, $quantityModel);
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $key = $packageKey . '-norm-intent-' . ($index + 1);
        $flags = ['normative_required'];

        if (($quantity['review_required'] ?? false) === true) {
            $flags[] = 'quantity_review_required';
        }

        return $this->basePricedWorkItem(
            key: $key,
            localEstimate: $localEstimate,
            section: $section,
            name: (string) $definition['name'],
            searchText: (string) ($definition['normative_search_text'] ?? $definition['name']),
            category: (string) $definition['category'],
            unit: (string) $quantity['unit'],
            quantity: (float) $quantity['value'],
            quantityFormula: (string) $definition['quantity_key'],
            quantityBasis: (string) $quantity['basis'],
            sourceRefs: $quantity['source_refs'] !== [] ? $quantity['source_refs'] : ($section['source_refs'] ?? $localEstimate['source_refs'] ?? []),
            confidence: (float) ($definition['confidence'] ?? $quantity['confidence'] ?? 0.7),
            validationFlags: $flags,
            metadata: [
                'generation_source' => $definition['generation_source'] ?? 'normative_intent_catalog',
                'quantity_key' => $definition['quantity_key'],
                'package_key' => $packageKey,
                ...($definition['metadata'] ?? []),
            ],
            normativeRateCode: isset($definition['normative_rate_code']) ? (string) $definition['normative_rate_code'] : null,
            operations: $definition['operations'] ?? $this->operationBank((string) $definition['category'])
        );
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @param array<int, array<string, mixed>> $sourceRefs
     * @param array<int, string> $validationFlags
     * @param array<string, mixed> $metadata
     * @param array<int, string> $operations
     * @return array<string, mixed>
     */
    private function basePricedWorkItem(
        string $key,
        array $localEstimate,
        array $section,
        string $name,
        string $searchText,
        string $category,
        string $unit,
        float $quantity,
        string $quantityFormula,
        string $quantityBasis,
        array $sourceRefs,
        float $confidence,
        array $validationFlags,
        array $metadata,
        ?string $normativeRateCode,
        array $operations
    ): array {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $scopeType = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? 'custom');
        $searchKey = $this->normativeSearchKey($packageKey, $scopeType, $category, $searchText, $unit, $quantityFormula, $normativeRateCode);

        return [
            'key' => $key,
            'parent_key' => null,
            'level' => 0,
            'item_type' => 'priced_work',
            'name' => $name,
            'description' => $searchText,
            'normative_search_text' => $searchText,
            'normative_search_key' => $searchKey,
            'normative_rate_code' => $normativeRateCode,
            'work_category' => $category,
            'unit' => $unit,
            'quantity' => round(max($quantity, 0.01), 4),
            'quantity_formula' => $quantityFormula,
            'quantity_basis' => $quantityBasis,
            'work_cost' => 0,
            'materials_cost' => 0,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_composition' => array_values($operations),
            'source_refs' => $this->normalizeSourceRefs($sourceRefs),
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'validation_flags' => array_values(array_unique($validationFlags)),
            'price_source' => null,
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'normative_required',
            'pricing_blocker_message' => null,
            'metadata' => [
                ...$metadata,
                'normative_grounding_policy' => 'fsnb_required',
                'display_role' => 'priced_work',
                'work_composition' => array_values($operations),
                'composition_source' => 'planner_intent',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @return array<int, array<string, mixed>>
     */
    private function withOperationRows(array $items, array $localEstimate, array $section): array
    {
        $targetMin = max((int) ($localEstimate['target_items_min'] ?? 0), count($items));
        $targetMax = max((int) ($localEstimate['target_items_max'] ?? 0), $targetMin);
        $expanded = [];

        foreach ($items as $item) {
            $expanded[] = $item;
            $operations = $item['work_composition'] ?? $this->operationBank((string) ($item['work_category'] ?? 'custom'));

            foreach ($operations as $operationIndex => $operationName) {
                if (count($expanded) >= $targetMin && count($expanded) >= $targetMax) {
                    break;
                }

                $expanded[] = $this->operationRow($item, (string) $operationName, $operationIndex);

                if (count($expanded) >= $targetMin) {
                    break;
                }
            }
        }

        $cycle = 0;
        while ($items !== [] && count($expanded) < $targetMin) {
            $item = $items[$cycle % count($items)];
            $operations = $this->operationBank((string) ($item['work_category'] ?? $section['construction_part'] ?? 'custom'));
            $operationName = $operations[$cycle % count($operations)] ?? 'Проверка состава работ';
            $expanded[] = $this->operationRow($item, $operationName, $cycle + 100);
            $cycle++;
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $parent
     * @return array<string, mixed>
     */
    private function operationRow(array $parent, string $name, int $index): array
    {
        return [
            'key' => (string) $parent['key'] . '-op-' . ($index + 1),
            'parent_key' => $parent['key'],
            'level' => 1,
            'item_type' => 'operation',
            'name' => $name,
            'description' => $name,
            'work_category' => $parent['work_category'] ?? 'custom',
            'unit' => '',
            'quantity' => 0,
            'quantity_formula' => 'work_composition',
            'quantity_basis' => 'Состав работ по сметной норме.',
            'work_cost' => 0,
            'materials_cost' => 0,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_composition' => [],
            'source_refs' => $parent['source_refs'] ?? [],
            'confidence' => $parent['confidence'] ?? 0.7,
            'validation_flags' => [],
            'price_source' => null,
            'metadata' => [
                'generation_source' => 'normative_work_composition',
                'parent_normative_search_key' => $parent['normative_search_key'] ?? null,
                'display_role' => 'operation',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $quantityModel
     * @return array<int, array<string, mixed>>
     */
    private function definitions(array $localEstimate, array $section, array $analysis, array $quantityModel): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? '');
        $scopeType = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? '');
        $definitions = [
            ...$this->packageDefinitions($packageKey, $scopeType, $quantityModel),
            ...$this->scopeInferenceDefinitions($analysis, $scopeType),
        ];

        if ($definitions === []) {
            $definitions = $this->packageDefinitions('custom', $scopeType, $quantityModel);
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $quantityModel
     * @return array<int, array<string, mixed>>
     */
    private function packageDefinitions(string $packageKey, string $scopeType, array $quantityModel): array
    {
        $flatRoof = (string) ($quantityModel['features']['roof_type'] ?? '') === 'flat';

        return match ($packageKey) {
            'preconstruction', 'site_preparation' => [
                $this->definition('Подготовка строительной площадки', 'site', 'подготовка строительной площадки', 'site.setup'),
                $this->definition('Временное ограждение площадки', 'site', 'устройство временного ограждения строительной площадки', 'site.fence'),
                $this->definition('Геодезическая разбивка осей', 'site', 'геодезическая разбивка осей здания', 'site.geodesy'),
                $this->definition('Планировка основания площадки', 'earthworks', 'планировка площадки механизированным способом', 'earth.plan'),
            ],
            'earthworks' => [
                $this->definition('Разработка грунта под фундаменты', 'earthworks', 'разработка грунта в траншеях и котлованах', 'earth.trench'),
                $this->definition('Обратная засыпка пазух', 'earthworks', 'обратная засыпка грунта с уплотнением', 'earth.backfill'),
                $this->definition('Вывоз излишнего грунта', 'earthworks', 'погрузка и перевозка излишнего грунта', 'earth.export'),
                $this->definition('Планировка основания', 'earthworks', 'планировка основания под фундаменты', 'earth.plan'),
            ],
            'foundation', 'foundations' => [
                $this->definition('Песчано-щебеночная подготовка', 'foundation', 'устройство песчано-щебеночной подготовки', 'foundation.prep'),
                $this->definition('Опалубка фундаментов', 'foundation', 'устройство опалубки фундаментов', 'foundation.formwork'),
                $this->definition('Армирование фундаментов', 'foundation', 'армирование железобетонных фундаментов', 'foundation.rebar'),
                $this->definition('Бетонирование фундаментов', 'foundation', 'бетонирование железобетонных фундаментов', 'foundation.concrete'),
                $this->definition('Гидроизоляция фундаментов', 'foundation', 'гидроизоляция фундаментов', 'foundation.waterproofing'),
            ],
            'walls', 'office_partitions' => [
                $this->definition('Кладка наружных стен', 'walls', 'кладка наружных стен', 'walls.external_volume'),
                $this->definition('Устройство внутренних перегородок', 'walls', 'устройство внутренних перегородок', 'walls.internal'),
                $this->definition('Устройство перемычек', 'walls', 'устройство перемычек над проемами', 'walls.lintels'),
                $this->definition('Офисные перегородки', 'walls', 'монтаж офисных перегородок', 'office.partitions'),
            ],
            'slabs', 'industrial_floor' => [
                $this->definition('Устройство плиты пола', 'slabs', 'устройство железобетонной плиты пола', 'warehouse.floor_concrete'),
                $this->definition('Армирование плиты пола', 'slabs', 'армирование железобетонной плиты пола', 'warehouse.floor_rebar'),
                $this->definition('Топпинг промышленного пола', 'industrial_floor', 'упрочнение верхнего слоя промышленного пола', 'warehouse.floor_hardener'),
                $this->definition('Деформационные швы пола', 'industrial_floor', 'нарезка и герметизация деформационных швов', 'warehouse.floor_joints'),
            ],
            'metal_frame' => [
                $this->definition('Монтаж металлических колонн', 'metal_frame', 'монтаж металлических колонн каркаса', 'warehouse.columns'),
                $this->definition('Монтаж балок и ферм', 'metal_frame', 'монтаж балок ферм и связей металлокаркаса', 'warehouse.beams'),
                $this->definition('Антикоррозионная защита металла', 'metal_frame', 'антикоррозионная защита металлических конструкций', 'warehouse.frame_weight'),
            ],
            'envelope', 'facade' => [
                $this->definition('Монтаж стеновых панелей', 'facade', 'монтаж стеновых сэндвич-панелей', 'warehouse.wall_panels'),
                $this->definition('Монтаж фасонных элементов', 'facade', 'монтаж доборных элементов фасада', 'warehouse.panel_flashings'),
                $this->definition('Отделка фасада', 'facade', 'отделка фасада здания', 'facade.area'),
            ],
            'roof' => $flatRoof ? [
                $this->definition('Устройство основания плоской кровли', 'roof', 'устройство основания плоской кровли', 'roof.flat_area'),
                $this->definition('Пароизоляция плоской кровли', 'roof', 'устройство пароизоляции плоской кровли', 'roof.flat_area'),
                $this->definition('Утепление плоской кровли', 'roof', 'утепление плоской кровли', 'roof.flat_area'),
                $this->definition('Гидроизоляционный ковер кровли', 'roof', 'устройство рулонной гидроизоляции кровли', 'roof.flat_area'),
                $this->definition('Водоотвод плоской кровли', 'roof', 'устройство внутреннего водостока кровли', 'roof.gutter'),
            ] : [
                $this->definition('Монтаж стропильной системы', 'roof', 'монтаж стропильной системы кровли', 'roof.area'),
                $this->definition('Утепление кровли', 'roof', 'утепление скатной кровли', 'roof.area'),
                $this->definition('Монтаж кровельного покрытия', 'roof', 'монтаж кровельного покрытия', 'roof.area'),
                $this->definition('Водосточная система кровли', 'roof', 'монтаж водосточной системы кровли', 'roof.gutter'),
            ],
            'openings', 'gates', 'entrance_group' => [
                $this->definition('Монтаж оконных блоков', 'openings', 'монтаж оконных блоков', 'openings.windows'),
                $this->definition('Монтаж дверных блоков', 'openings', 'монтаж дверных блоков', 'openings.doors'),
                $this->definition('Монтаж ворот', 'openings', 'монтаж промышленных ворот', 'warehouse.gates'),
                $this->definition('Погрузочные узлы', 'openings', 'устройство погрузочных узлов', 'warehouse.loading_nodes'),
            ],
            'electrical', 'power_supply' => [
                $this->definition('Прокладка магистральных кабелей', 'electrical', 'прокладка магистральных кабельных линий', 'electrical.main_cable'),
                $this->definition('Монтаж кабельных лотков', 'electrical', 'монтаж кабельных лотков', 'electrical.trays'),
                $this->definition('Прокладка силовых линий', 'electrical', 'прокладка силовых кабельных линий', 'electrical.power_lines'),
                $this->definition('Устройство заземления', 'electrical', 'устройство контура заземления', 'electrical.grounding'),
            ],
            'lighting' => [
                $this->definition('Прокладка линий освещения', 'electrical', 'прокладка групповых линий освещения', 'lighting.lines'),
                $this->definition('Монтаж светильников', 'electrical', 'монтаж промышленных светильников', 'warehouse.lighting'),
            ],
            'low_current', 'server_room' => [
                $this->definition('Прокладка слаботочных трасс', 'electrical', 'прокладка слаботочных кабельных линий', 'warehouse.low_current'),
                $this->definition('Монтаж СКС', 'electrical', 'монтаж структурированной кабельной сети', 'office.network_points'),
                $this->definition('Серверная и связь', 'electrical', 'монтаж оборудования серверной', 'server.room'),
            ],
            'plumbing', 'water_sewerage', 'sanitary_rooms' => [
                $this->definition('Прокладка труб водоснабжения', 'plumbing', 'прокладка трубопроводов водоснабжения', 'plumbing.pipe'),
                $this->definition('Сантехнические точки', 'plumbing', 'подключение сантехнических приборов', 'sanitary.points'),
                $this->definition('Прокладка труб канализации', 'sewerage', 'прокладка трубопроводов канализации', 'sewerage.pipe'),
                $this->definition('Гидроизоляция и плитка мокрых зон', 'finishing', 'отделка мокрых зон плиткой', 'sanitary.tile'),
            ],
            'heating' => [
                $this->definition('Тепловой узел', 'heating', 'монтаж теплового узла', 'heating.unit'),
                $this->definition('Прокладка труб отопления', 'heating', 'прокладка трубопроводов отопления', 'heating.pipe'),
                $this->definition('Монтаж радиаторов', 'heating', 'монтаж отопительных приборов', 'heating.radiators'),
                $this->definition('Воздушно-тепловые завесы', 'heating', 'монтаж воздушно-тепловых завес', 'heating.air_curtains'),
            ],
            'ventilation' => [
                $this->definition('Приточно-вытяжная вентиляция', 'ventilation', 'монтаж приточно-вытяжной вентиляции', 'ventilation.air_exchange'),
                $this->definition('Воздухораспределители склада', 'ventilation', 'монтаж воздухораспределителей складской зоны', 'ventilation.warehouse_points'),
                $this->definition('Воздухораспределители офиса', 'ventilation', 'монтаж воздухораспределителей офисной зоны', 'ventilation.office_points'),
            ],
            'fire_safety' => [
                $this->definition('Пожарная сигнализация', 'engineering', 'монтаж пожарной сигнализации и оповещения', 'warehouse.fire'),
            ],
            'rough_finishing', 'finish_finishing', 'office_finishing' => [
                $this->definition('Черновая подготовка пола', 'finishing', 'устройство черновой подготовки пола', 'rough.floor'),
                $this->definition('Черновая подготовка стен', 'finishing', 'подготовка поверхностей стен', 'rough.walls'),
                $this->definition('Чистовое покрытие пола', 'finishing', 'устройство чистового покрытия пола', 'finish.floor'),
                $this->definition('Окраска стен', 'finishing', 'окраска стен', 'finish.paint'),
                $this->definition('Подвесной потолок', 'finishing', 'монтаж подвесного потолка', 'office.ceiling'),
            ],
            'external_networks', 'siteworks', 'roads' => [
                $this->definition('Наружные сети', 'site', 'устройство наружных инженерных сетей', 'networks.external'),
                $this->definition('Благоустройство территории', 'site', 'благоустройство территории', 'siteworks.area'),
                $this->definition('Дороги и площадки', 'site', 'устройство дорог и площадок', 'warehouse.roads'),
            ],
            default => $this->scopeDefinitions($scopeType),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scopeDefinitions(string $scopeType): array
    {
        return match ($scopeType) {
            'foundation' => $this->packageDefinitions('foundation', $scopeType, []),
            'electrical', 'engineering' => $this->packageDefinitions('electrical', $scopeType, []),
            'plumbing' => $this->packageDefinitions('plumbing', $scopeType, []),
            'heating' => $this->packageDefinitions('heating', $scopeType, []),
            'ventilation' => $this->packageDefinitions('ventilation', $scopeType, []),
            'roof' => $this->packageDefinitions('roof', $scopeType, ['features' => ['roof_type' => 'pitched']]),
            'finishing' => $this->packageDefinitions('office_finishing', $scopeType, []),
            default => [
                $this->definition('Комплекс строительных работ', 'custom', 'строительные работы по проектной документации', 'site.setup'),
            ],
        };
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    private function scopeInferenceDefinitions(array $analysis, string $scopeType): array
    {
        $definitions = [];

        foreach ($this->scopeInferenceService->inferFromAnalysis($analysis) as $inference) {
            $inferenceScope = (string) ($inference['scope_type'] ?? '');
            $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];
            $quantityKey = (string) ($payload['quantity_key'] ?? '');

            if ($quantityKey === '' || !$this->scopeCompatible($inferenceScope, $scopeType)) {
                continue;
            }

            $definitions[] = $this->definition(
                (string) ($inference['title'] ?? $this->titleForScope($inferenceScope)),
                $inferenceScope,
                (string) ($inference['title'] ?? $this->titleForScope($inferenceScope)),
                $quantityKey,
                confidence: (float) ($inference['confidence'] ?? 0.74),
                metadata: [
                    'generation_source' => 'scope_inference',
                    'scope_inference' => $inference,
                ]
            );
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $quantityModel
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool}
     */
    private function quantityForDefinition(array $definition, array $analysis, array $quantityModel): array
    {
        $quantityKey = (string) $definition['quantity_key'];
        $quantities = is_array($quantityModel['quantities'] ?? null) ? $quantityModel['quantities'] : [];

        if (is_array($quantities[$quantityKey] ?? null)) {
            return [
                'value' => (float) $quantities[$quantityKey]['value'],
                'unit' => (string) $quantities[$quantityKey]['unit'],
                'basis' => (string) $quantities[$quantityKey]['basis'],
                'confidence' => (float) ($quantities[$quantityKey]['confidence'] ?? 0.72),
                'source_refs' => is_array($quantities[$quantityKey]['source_refs'] ?? null)
                    ? $this->normalizeSourceRefs($quantities[$quantityKey]['source_refs'])
                    : $this->sourceRefsForQuantityKey($analysis, $quantityKey),
                'review_required' => false,
            ];
        }

        $inference = is_array($definition['metadata']['scope_inference'] ?? null) ? $definition['metadata']['scope_inference'] : [];
        $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];

        if (isset($payload['quantity_value'])) {
            return [
                'value' => (float) $payload['quantity_value'],
                'unit' => (string) ($payload['unit'] ?? 'ед'),
                'basis' => 'Количество извлечено из чертежа и требует сметной проверки.',
                'confidence' => (float) ($inference['confidence'] ?? 0.74),
                'source_refs' => isset($inference['source_ref']) && is_array($inference['source_ref']) ? [$inference['source_ref']] : [],
                'review_required' => (bool) ($inference['review_required'] ?? true),
            ];
        }

        return [
            'value' => 1.0,
            'unit' => 'компл',
            'basis' => 'Количество не найдено в документации и требует проверки сметчиком.',
            'confidence' => 0.48,
            'source_refs' => [],
            'review_required' => true,
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array{quantities: array<string, array<string, mixed>>, features: array<string, mixed>}
     */
    private function documentQuantityModel(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $quantities = $this->quantitiesFromFactsSummary($documentContext);

        foreach ($this->quantitiesFromTakeoffs($documentContext) as $key => $quantity) {
            $quantities[$key] = $quantity;
        }

        return [
            'quantities' => $quantities,
            'features' => [
                'roof_type' => $this->roofTypeFromDocumentContext($analysis, $documentContext),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $documentContext
     * @return array<string, array<string, mixed>>
     */
    private function quantitiesFromTakeoffs(array $documentContext): array
    {
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];
        $quantities = [];

        foreach ($takeoffs as $takeoff) {
            if (!is_array($takeoff)) {
                continue;
            }

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $quantityKey = (string) ($payload['quantity_key'] ?? $takeoff['quantity_key'] ?? $this->quantityKeyFromTakeoffScope((string) ($takeoff['scope_key'] ?? '')));
            $value = $this->firstNumeric($takeoff, ['quantity', 'value', 'value_number']);

            if ($quantityKey === '' || $value === null || $value <= 0) {
                continue;
            }

            $quantities[$quantityKey] = $this->modelQuantity(
                value: $value,
                unit: (string) ($takeoff['unit'] ?? $payload['unit'] ?? 'ед'),
                basis: (string) ($takeoff['name'] ?? $takeoff['label'] ?? $takeoff['formula'] ?? 'Количество извлечено из проектной документации.'),
                confidence: (float) ($takeoff['confidence'] ?? 0.76),
                sourceRefs: is_array($takeoff['source_refs'] ?? null) ? $takeoff['source_refs'] : []
            );
        }

        return $quantities;
    }

    /**
     * @param array<string, mixed> $documentContext
     * @return array<string, array<string, mixed>>
     */
    private function quantitiesFromFactsSummary(array $documentContext): array
    {
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];
        $quantities = [];
        $area = $this->numericValue($factsSummary['total_area_m2'] ?? null);

        if ($area !== null && $area > 0) {
            foreach (['rough.floor', 'finish.floor', 'ventilation.air_exchange', 'warehouse.fire'] as $quantityKey) {
                $quantities[$quantityKey] = $this->modelQuantity($area, 'м2', 'Площадь объекта извлечена из проектной документации.', 0.72, []);
            }
        }

        foreach (($factsSummary['zones'] ?? []) as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $zoneArea = $this->numericValue($zone['area_m2'] ?? null);
            $scopeKey = (string) ($zone['scope_key'] ?? '');

            if ($zoneArea === null || $zoneArea <= 0) {
                continue;
            }

            $sourceRefs = isset($zone['source_ref']) && is_array($zone['source_ref']) ? [$zone['source_ref']] : [];

            foreach ($this->zoneQuantityKeys($scopeKey) as $quantityKey) {
                $quantities[$quantityKey] = $this->modelQuantity(
                    $zoneArea,
                    'м2',
                    (string) ($zone['label'] ?? 'Площадь зоны извлечена из проектной документации.'),
                    (float) ($zone['confidence'] ?? 0.72),
                    $sourceRefs
                );
            }
        }

        return $quantities;
    }

    /**
     * @return array<int, string>
     */
    private function zoneQuantityKeys(string $scopeKey): array
    {
        return match ($scopeKey) {
            'warehouse_area', 'warehouse', 'industrial_floor' => [
                'warehouse.floor',
                'warehouse.floor_hardener',
                'warehouse.wall_panels',
            ],
            'office_area', 'office' => [
                'office.floor',
                'office.ceiling',
                'office.floor_finish',
            ],
            'room_area', 'finishing' => [
                'rough.floor',
                'finish.floor',
            ],
            default => [],
        };
    }

    /**
     * @param array<int, mixed> $sourceRefs
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>}
     */
    private function modelQuantity(float $value, string $unit, string $basis, float $confidence, array $sourceRefs): array
    {
        return [
            'value' => round($value, 4),
            'unit' => $unit,
            'basis' => $basis,
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'source_refs' => $this->normalizeSourceRefs($sourceRefs),
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $documentContext
     */
    private function roofTypeFromDocumentContext(array $analysis, array $documentContext): string
    {
        $haystack = mb_strtolower(implode(' ', $this->documentTextFragments($analysis, $documentContext)));

        return str_contains($haystack, 'плоск') || str_contains($haystack, 'flat') ? 'flat' : 'pitched';
    }

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $documentContext
     * @return array<int, string>
     */
    private function documentTextFragments(array $analysis, array $documentContext): array
    {
        $fragments = [];
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];

        foreach ([$object['description'] ?? null, $object['building_type'] ?? null, $object['object_type'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $fragments[] = $value;
            }
        }

        foreach (['facts', 'scope_inferences', 'drawing_elements'] as $collectionKey) {
            $items = is_array($documentContext[$collectionKey] ?? null) ? $documentContext[$collectionKey] : [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach (['label', 'title', 'description', 'value_text', 'name', 'type'] as $field) {
                    if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
                        $fragments[] = $item[$field];
                    }
                }
            }
        }

        return $fragments;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function firstNumeric(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->numericValue($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float) str_replace(',', '.', $value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefsForQuantityKey(array $analysis, string $quantityKey): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];
        $refs = [];

        foreach ($takeoffs as $takeoff) {
            if (!is_array($takeoff)) {
                continue;
            }

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $takeoffKey = (string) ($payload['quantity_key'] ?? $takeoff['quantity_key'] ?? $this->quantityKeyFromTakeoffScope((string) ($takeoff['scope_key'] ?? '')));

            if ($takeoffKey === $quantityKey && is_array($takeoff['source_refs'] ?? null)) {
                foreach ($takeoff['source_refs'] as $sourceRef) {
                    if (is_array($sourceRef)) {
                        $refs[] = $sourceRef;
                    }
                }
            }
        }

        return $refs;
    }

    private function quantityKeyFromTakeoffScope(string $scopeKey): string
    {
        return match ($scopeKey) {
            'room_area' => 'finish.floor',
            'opening_count' => 'openings.doors',
            'engineering_route_length' => 'plumbing.pipe',
            default => '',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function uniquePricedItems(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $key = (string) ($item['normative_rate_code'] ?? $item['normative_search_key'] ?? $item['key']);
            $unique[$key] = $item;
        }

        return array_values($unique);
    }

    /**
     * @param array<int, mixed> $sourceRefs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSourceRefs(array $sourceRefs): array
    {
        return array_values(array_filter($sourceRefs, 'is_array'));
    }

    private function scopeCompatible(string $inferenceScope, string $targetScope): bool
    {
        if ($targetScope === '' || $targetScope === 'custom' || $inferenceScope === $targetScope) {
            return true;
        }

        return match ($targetScope) {
            'engineering' => in_array($inferenceScope, ['electrical', 'plumbing', 'heating', 'ventilation'], true),
            'finishing' => in_array($inferenceScope, ['finishing', 'openings'], true),
            default => false,
        };
    }

    /**
     * @param array<int, string> $operations
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function definition(
        string $name,
        string $category,
        string $searchText,
        string $quantityKey,
        array $operations = [],
        float $confidence = 0.7,
        array $metadata = []
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'normative_search_text' => $searchText,
            'quantity_key' => $quantityKey,
            'operations' => $operations !== [] ? $operations : $this->operationBank($category),
            'confidence' => $confidence,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function operationBank(string $category): array
    {
        return match ($category) {
            'earthworks' => ['Разметка участка', 'Разработка грунта', 'Погрузка грунта', 'Вывоз грунта', 'Уплотнение основания'],
            'foundation' => ['Подготовка основания', 'Монтаж опалубки', 'Вязка арматуры', 'Укладка бетонной смеси', 'Уход за бетоном'],
            'walls' => ['Разметка осей', 'Подача материалов', 'Кладка конструкций', 'Армирование рядов', 'Контроль плоскости'],
            'slabs', 'industrial_floor' => ['Подготовка основания', 'Монтаж арматуры', 'Укладка бетонной смеси', 'Выравнивание поверхности', 'Устройство швов'],
            'metal_frame' => ['Разметка осей', 'Монтаж элементов', 'Болтовые соединения', 'Выверка геометрии', 'Защитное покрытие'],
            'roof' => ['Подготовка основания', 'Устройство изоляции', 'Монтаж покрытия', 'Устройство примыканий', 'Контроль герметичности'],
            'facade' => ['Подготовка основания', 'Монтаж подсистемы', 'Монтаж облицовки', 'Устройство примыканий', 'Контроль креплений'],
            'openings' => ['Подготовка проема', 'Монтаж блока', 'Крепление', 'Герметизация примыканий', 'Регулировка'],
            'electrical' => ['Разметка трасс', 'Прокладка линий', 'Монтаж креплений', 'Подключение', 'Измерения'],
            'plumbing', 'sewerage', 'heating' => ['Разметка трасс', 'Прокладка труб', 'Монтаж арматуры', 'Крепление', 'Испытания'],
            'ventilation' => ['Разметка трасс', 'Монтаж воздуховодов', 'Монтаж решеток', 'Крепление', 'Пусковая проверка'],
            'finishing' => ['Подготовка поверхности', 'Грунтование', 'Основной слой', 'Выравнивание', 'Финишный контроль'],
            default => ['Подготовка фронта работ', 'Поставка материалов', 'Основной монтаж', 'Крепление', 'Контроль качества'],
        };
    }

    private function titleForScope(string $scopeType): string
    {
        return match ($scopeType) {
            'electrical' => 'Электроснабжение',
            'plumbing' => 'Водоснабжение и канализация',
            'heating' => 'Отопление',
            'ventilation' => 'Вентиляция',
            'openings' => 'Окна и двери',
            'finishing' => 'Отделка помещений',
            default => 'Строительные работы',
        };
    }

    private function normativeSearchKey(
        string $packageKey,
        string $scopeType,
        string $category,
        string $searchText,
        string $unit,
        string $quantityKey,
        ?string $normativeRateCode
    ): string {
        return implode('|', array_map(
            fn (string $value): string => $this->normalizeSearchPart($value),
            [
                $normativeRateCode ?? '',
                $packageKey,
                $scopeType,
                $category,
                $searchText,
                $unit,
                $quantityKey,
            ]
        ));
    }

    private function normalizeSearchPart(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
