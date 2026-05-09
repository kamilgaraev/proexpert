<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class WorkItemGenerationService
{
    public function __construct(
        protected MarketFallbackPricingService $fallbackPricingService,
        protected ObjectQuantityModelService $quantityModelService,
    ) {}

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    public function build(array $localEstimate, array $analysis): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? '');
        $scopeType = (string) ($localEstimate['scope_type'] ?? 'custom');
        $targetItemsMin = (int) ($localEstimate['target_items_min'] ?? 0);
        $quantityModel = $this->quantityModelService->build($analysis);
        $templates = $this->templateForPackage($packageKey, $scopeType);
        $workItems = [];

        foreach ($templates as $index => $template) {
            $workItem = $this->pricedWorkItem($localEstimate, $template, $quantityModel, $index);
            $resources = $this->fallbackPricingService->resourcesForTemplate($template, $workItem);
            $workItem['materials'] = $resources['materials'];
            $workItem['labor'] = $resources['labor'];
            $workItem['machinery'] = $resources['machinery'];
            $workItems[] = $workItem;
        }

        return $this->withOperationDetails($workItems, $localEstimate, $targetItemsMin);
    }

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $template
     * @param array<string, mixed> $quantityModel
     * @return array<string, mixed>
     */
    private function pricedWorkItem(array $localEstimate, array $template, array $quantityModel, int $index): array
    {
        $quantity = $this->quantityForTemplate($template, $quantityModel);
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $scopeType = (string) ($localEstimate['scope_type'] ?? 'custom');
        $key = $packageKey . '-work-' . ($index + 1);

        return [
            'key' => $key,
            'parent_key' => null,
            'level' => 0,
            'item_type' => 'priced_work',
            'name' => $template['name'],
            'normative_search_text' => $template['normative_search_text'] ?? $template['name'],
            'normative_search_key' => $this->normativeSearchKey($packageKey, $scopeType, $template),
            'work_category' => $template['category'],
            'description' => $template['description'],
            'unit' => $quantity['unit'],
            'quantity' => $quantity['value'],
            'quantity_formula' => $quantity['formula'],
            'quantity_basis' => $quantity['basis'],
            'work_cost' => 0,
            'materials_cost' => 0,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'source_refs' => $localEstimate['source_refs'] ?? [],
            'confidence' => $template['confidence'],
            'validation_flags' => ['market_price_used'],
            'price_source' => 'market_estimate',
            'metadata' => [
                'package_key' => $packageKey,
                'quantity_key' => $template['quantity_key'],
                'display_role' => 'priced_work',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $workItems
     * @param array<string, mixed> $localEstimate
     * @return array<int, array<string, mixed>>
     */
    private function withOperationDetails(array $workItems, array $localEstimate, int $targetItemsMin): array
    {
        if ($targetItemsMin <= count($workItems)) {
            return $workItems;
        }

        $expanded = $workItems;
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $operationIndex = 1;

        foreach ($workItems as $workItem) {
            foreach ($this->detailVariants((string) $workItem['work_category']) as $variant) {
                $expanded[] = [
                    'key' => $packageKey . '-operation-' . $operationIndex,
                    'parent_key' => $workItem['key'],
                    'level' => 1,
                    'item_type' => 'operation',
                    'name' => $variant,
                    'normative_search_text' => null,
                    'normative_search_key' => $this->normalizeSearchPart($packageKey . '|operation|' . $workItem['key'] . '|' . $variant),
                    'work_category' => $workItem['work_category'],
                    'description' => 'Операция входит в состав позиции "' . $workItem['name'] . '"',
                    'unit' => 'операция',
                    'quantity' => 1,
                    'quantity_formula' => 'Состав основной позиции',
                    'quantity_basis' => 'Не является отдельной платной строкой. Используется для проверки состава работ.',
                    'work_cost' => 0,
                    'materials_cost' => 0,
                    'machinery_cost' => 0,
                    'labor_cost' => 0,
                    'total_cost' => 0,
                    'materials' => [],
                    'labor' => [],
                    'machinery' => [],
                    'other_resources' => [],
                    'source_refs' => $localEstimate['source_refs'] ?? [],
                    'confidence' => $workItem['confidence'],
                    'validation_flags' => [],
                    'price_source' => 'not_priced',
                    'skip_normative_matching' => true,
                    'normative_match' => ['status' => 'not_applicable'],
                    'metadata' => [
                        'package_key' => $packageKey,
                        'display_role' => 'operation',
                    ],
                ];
                $operationIndex++;

                if (count($expanded) >= $targetItemsMin) {
                    return $expanded;
                }
            }
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $quantityModel
     * @return array{value: float, unit: string, basis: string, formula: string}
     */
    private function quantityForTemplate(array $template, array $quantityModel): array
    {
        $quantityKey = (string) $template['quantity_key'];
        $quantities = is_array($quantityModel['quantities'] ?? null) ? $quantityModel['quantities'] : [];
        $quantity = is_array($quantities[$quantityKey] ?? null) ? $quantities[$quantityKey] : null;

        if ($quantity === null) {
            return [
                'value' => (float) ($template['base_quantity'] ?? 1),
                'unit' => (string) ($template['unit'] ?? 'компл'),
                'basis' => 'Ориентировочный объем по типу работ',
                'formula' => $quantityKey,
            ];
        }

        return [
            'value' => (float) $quantity['value'],
            'unit' => (string) $quantity['unit'],
            'basis' => (string) $quantity['basis'],
            'formula' => $quantityKey,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normativeSearchKey(string $packageKey, string $scopeType, array $item): string
    {
        return implode('|', [
            $this->normalizeSearchPart($packageKey),
            $this->normalizeSearchPart($scopeType),
            $this->normalizeSearchPart((string) ($item['category'] ?? 'custom')),
            $this->normalizeSearchPart((string) ($item['normative_search_text'] ?? $item['name'] ?? '')),
            $this->normalizeSearchPart((string) ($item['unit'] ?? '')),
        ]);
    }

    private function normalizeSearchPart(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function detailVariants(string $category): array
    {
        return match ($category) {
            'earthworks' => ['Разметка участка', 'Разработка грунта', 'Погрузка грунта', 'Вывоз грунта', 'Обратная засыпка', 'Уплотнение основания'],
            'foundation' => ['Подготовка основания', 'Монтаж опалубки', 'Вязка арматуры', 'Укладка бетона', 'Уход за бетоном', 'Контроль геометрии'],
            'masonry' => ['Разметка осей', 'Подача материалов', 'Основная кладка', 'Армирование рядов', 'Устройство перемычек', 'Контроль плоскости'],
            'stairs' => ['Разметка проема', 'Монтаж несущих элементов', 'Устройство ступеней', 'Монтаж ограждений', 'Регулировка и приемка'],
            'electrical' => ['Разметка трасс', 'Прокладка линий', 'Монтаж коробок', 'Установка оборудования', 'Подключение', 'Измерения'],
            'plumbing', 'sewerage', 'heating' => ['Разметка трасс', 'Прокладка труб', 'Монтаж арматуры', 'Крепление', 'Опрессовка', 'Пусковая проверка'],
            'ventilation' => ['Разметка трасс', 'Монтаж каналов', 'Установка решеток', 'Крепление', 'Проверка тяги'],
            'finishing' => ['Подготовка поверхности', 'Грунтование', 'Основной слой', 'Выравнивание', 'Примыкания', 'Финишный контроль'],
            default => ['Подготовка фронта работ', 'Поставка материалов', 'Основной монтаж', 'Крепление', 'Контроль качества', 'Уборка участка'],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templateForPackage(string $packageKey, string $scopeType): array
    {
        return match ($packageKey) {
            'preconstruction' => [
                $this->template('Организация строительной площадки', 'site', 'Подготовка площадки к началу работ', 'site.setup', 45000, ['material' => 0.25, 'labor' => 0.55, 'machinery' => 0.2], 0.72),
                $this->template('Временное ограждение площадки', 'site', 'Ограждение зоны работ', 'site.fence', 950, ['material' => 0.55, 'labor' => 0.35, 'machinery' => 0.1], 0.7),
                $this->template('Временное электроснабжение', 'electrical', 'Подключение временного питания', 'site.power', 38000, ['material' => 0.6, 'labor' => 0.35, 'machinery' => 0.05], 0.66),
                $this->template('Разбивка осей здания', 'site', 'Геодезическая разбивка и контроль отметок', 'site.geodesy', 32000, ['material' => 0.05, 'labor' => 0.9, 'machinery' => 0.05], 0.74),
            ],
            'earthworks' => [
                $this->template('Разработка грунта под ленточный фундамент', 'earthworks', 'Траншеи под фундаментную ленту', 'earth.trench', 850, ['material' => 0.04, 'labor' => 0.36, 'machinery' => 0.6], 0.74),
                $this->template('Планировка основания', 'earthworks', 'Подготовка основания под фундамент', 'earth.plan', 180, ['material' => 0.05, 'labor' => 0.45, 'machinery' => 0.5], 0.7),
                $this->template('Обратная засыпка пазух', 'earthworks', 'Засыпка и уплотнение после устройства ленты', 'earth.backfill', 620, ['material' => 0.15, 'labor' => 0.35, 'machinery' => 0.5], 0.68),
                $this->template('Вывоз лишнего грунта', 'earthworks', 'Погрузка и вывоз излишков грунта', 'earth.export', 780, ['material' => 0.02, 'labor' => 0.18, 'machinery' => 0.8], 0.66),
            ],
            'foundation' => [
                $this->template('Песчано-щебеночная подготовка', 'foundation', 'Подготовка основания под ленту', 'foundation.prep', 2400, ['material' => 0.68, 'labor' => 0.18, 'machinery' => 0.14], 0.72),
                $this->template('Опалубка ленточного фундамента', 'foundation', 'Устройство и демонтаж опалубки', 'foundation.formwork', 1150, ['material' => 0.42, 'labor' => 0.48, 'machinery' => 0.1], 0.72),
                $this->template('Армирование фундаментной ленты', 'foundation', 'Заготовка и вязка арматурного каркаса', 'foundation.rebar', 92000, ['material' => 0.78, 'labor' => 0.2, 'machinery' => 0.02], 0.74),
                $this->template('Бетонирование фундаментной ленты B22.5', 'foundation', 'Укладка и вибрирование бетона', 'foundation.concrete', 9800, ['material' => 0.72, 'labor' => 0.18, 'machinery' => 0.1], 0.76),
                $this->template('Гидроизоляция фундамента', 'foundation', 'Вертикальная и горизонтальная гидроизоляция', 'foundation.waterproofing', 720, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.7),
            ],
            'walls' => [
                $this->template('Кладка наружных стен из газобетона D500 400 мм', 'masonry', 'Наружные стены жилого дома', 'walls.external_volume', 7600, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.74),
                $this->template('Кладка внутренних перегородок', 'masonry', 'Внутренние перегородки и простенки', 'walls.internal', 1450, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.7),
                $this->template('Устройство перемычек', 'masonry', 'Перемычки над проемами', 'walls.lintels', 6800, ['material' => 0.65, 'labor' => 0.3, 'machinery' => 0.05], 0.68),
            ],
            'slabs' => [
                $this->template('Устройство монолитного перекрытия', 'concrete', 'Опалубка, армирование и бетонирование перекрытия', 'slabs.area', 7800, ['material' => 0.7, 'labor' => 0.22, 'machinery' => 0.08], 0.7),
                $this->template('Армирование перекрытий', 'foundation', 'Арматурные сетки и каркасы перекрытий', 'slabs.rebar', 94000, ['material' => 0.8, 'labor' => 0.18, 'machinery' => 0.02], 0.68),
            ],
            'stairs' => [
                $this->template('Устройство межэтажной лестницы', 'stairs', 'Лестничный марш и площадка между этажами', 'stairs.flight', 145000, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.62),
                $this->template('Ограждения лестницы', 'stairs', 'Перила и ограждения лестничного марша', 'stairs.railing', 5200, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.6),
            ],
            'roof' => [
                $this->template('Монтаж стропильной системы', 'roof', 'Несущая деревянная конструкция кровли', 'roof.area', 1450, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.7),
                $this->template('Утепление кровли 200 мм', 'roof', 'Теплоизоляция кровельного пирога', 'roof.area', 1350, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.7),
                $this->template('Монтаж металлочерепицы', 'roof', 'Финишное кровельное покрытие', 'roof.area', 1850, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.72),
                $this->template('Водосточная система кровли', 'roof', 'Желоба, трубы и крепления', 'roof.gutter', 1250, ['material' => 0.65, 'labor' => 0.32, 'machinery' => 0.03], 0.66),
            ],
            'openings' => [
                $this->template('Установка окон ПВХ', 'openings', 'Оконные блоки с двухкамерным стеклопакетом', 'openings.windows', 14500, ['material' => 0.78, 'labor' => 0.2, 'machinery' => 0.02], 0.64),
                $this->template('Установка дверных блоков', 'openings', 'Входные и внутренние двери с монтажом', 'openings.doors', 22000, ['material' => 0.76, 'labor' => 0.22, 'machinery' => 0.02], 0.66),
            ],
            'facade' => [
                $this->template('Фасадная штукатурка по газобетону', 'finishing', 'Базовый и декоративный фасадный слой', 'facade.area', 1850, ['material' => 0.48, 'labor' => 0.48, 'machinery' => 0.04], 0.64),
            ],
            'electrical' => [
                $this->template('Монтаж электрического щита', 'electrical', 'Вводной щит и автоматика', 'site.power', 65000, ['material' => 0.72, 'labor' => 0.26, 'machinery' => 0.02], 0.66),
                $this->template('Прокладка кабельных линий', 'electrical', 'Кабельные линии по дому', 'electrical.cable', 390, ['material' => 0.55, 'labor' => 0.43, 'machinery' => 0.02], 0.64),
                $this->template('Монтаж розеток, выключателей и световых точек', 'electrical', 'Финишные электромонтажные точки', 'electrical.points', 1850, ['material' => 0.45, 'labor' => 0.53, 'machinery' => 0.02], 0.62),
            ],
            'plumbing' => [
                $this->template('Разводка водоснабжения', 'plumbing', 'Внутридомовые трубы водоснабжения', 'plumbing.pipe', 980, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.62),
                $this->template('Подключение сантехнических точек', 'plumbing', 'Коллекторы, арматура и подключение приборов', 'plumbing.points', 9200, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.62),
            ],
            'sewerage' => [
                $this->template('Разводка канализации', 'sewerage', 'Внутридомовые канализационные трубы', 'sewerage.pipe', 870, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.62),
                $this->template('Подключение точек канализации', 'sewerage', 'Подключение приборов к канализационной сети', 'sewerage.points', 7600, ['material' => 0.5, 'labor' => 0.45, 'machinery' => 0.05], 0.6),
            ],
            'heating' => [
                $this->template('Монтаж газового котла и обвязки', 'heating', 'Котельное оборудование и подключение', 'site.setup', 185000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.58),
                $this->template('Монтаж радиаторов отопления', 'heating', 'Радиаторы и арматура', 'heating.radiators', 13500, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.62),
                $this->template('Разводка труб отопления', 'heating', 'Трубная разводка системы отопления', 'heating.pipe', 980, ['material' => 0.56, 'labor' => 0.42, 'machinery' => 0.02], 0.62),
            ],
            'ventilation' => [
                $this->template('Устройство естественной вентиляции', 'ventilation', 'Вентиляционные каналы и выводы', 'ventilation.points', 12500, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.6),
            ],
            'rough_finishing' => [
                $this->template('Устройство стяжки пола', 'finishing', 'Черновое выравнивание пола', 'rough.floor', 1050, ['material' => 0.48, 'labor' => 0.45, 'machinery' => 0.07], 0.66),
                $this->template('Штукатурка внутренних стен', 'finishing', 'Черновая подготовка стен', 'rough.walls', 760, ['material' => 0.35, 'labor' => 0.6, 'machinery' => 0.05], 0.66),
            ],
            'finish_finishing' => [
                $this->template('Укладка ламината', 'finishing', 'Бюджетное напольное покрытие', 'finish.floor', 1550, ['material' => 0.64, 'labor' => 0.34, 'machinery' => 0.02], 0.58),
                $this->template('Укладка плитки во влажных зонах', 'finishing', 'Плиточные работы во влажных помещениях', 'finish.tile', 3200, ['material' => 0.55, 'labor' => 0.43, 'machinery' => 0.02], 0.56),
                $this->template('Окраска и обои под покраску', 'finishing', 'Финишная отделка стен', 'finish.paint', 980, ['material' => 0.48, 'labor' => 0.5, 'machinery' => 0.02], 0.56),
            ],
            'external_networks' => [
                $this->template('Наружные сети водоснабжения и канализации', 'plumbing', 'Подключение наружных инженерных сетей', 'networks.external', 280000, ['material' => 0.62, 'labor' => 0.2, 'machinery' => 0.18], 0.52),
            ],
            'siteworks' => [
                $this->template('Благоустройство территории', 'site', 'Отмостка, дорожки и минимальная планировка', 'siteworks.area', 2300, ['material' => 0.56, 'labor' => 0.32, 'machinery' => 0.12], 0.54),
            ],
            'site_preparation' => [
                $this->template('Подготовка площадки склада', 'site', 'Планировка и подготовка строительной площадки', 'siteworks.area', 1600, ['material' => 0.18, 'labor' => 0.32, 'machinery' => 0.5], 0.64),
                $this->template('Временные проезды и складирование', 'site', 'Организация временной логистики на площадке', 'warehouse.roads', 1450, ['material' => 0.42, 'labor' => 0.2, 'machinery' => 0.38], 0.6),
            ],
            'foundations', 'foundations_warehouse' => [
                $this->template('Фундаменты под колонны склада', 'foundation', 'Железобетонные основания под металлокаркас', 'foundation.concrete', 11200, ['material' => 0.7, 'labor' => 0.2, 'machinery' => 0.1], 0.68),
                $this->template('Армирование фундаментов склада', 'foundation', 'Арматурные каркасы фундаментов под колонны', 'foundation.rebar', 96000, ['material' => 0.8, 'labor' => 0.18, 'machinery' => 0.02], 0.68),
            ],
            'industrial_floor' => [
                $this->template('Устройство промышленного бетонного пола', 'industrial_floor', 'Бетонная плита пола склада с упрочнением', 'warehouse.floor', 4100, ['material' => 0.68, 'labor' => 0.22, 'machinery' => 0.1], 0.7),
                $this->template('Бетон промышленной плиты пола', 'industrial_floor', 'Бетонная смесь для промышленного пола', 'warehouse.floor_concrete', 9800, ['material' => 0.76, 'labor' => 0.12, 'machinery' => 0.12], 0.7),
            ],
            'metal_frame' => [
                $this->template('Монтаж металлокаркаса склада', 'metal_frame', 'Колонны, балки и связи каркаса', 'warehouse.frame_weight', 155000, ['material' => 0.78, 'labor' => 0.14, 'machinery' => 0.08], 0.66),
            ],
            'envelope' => [
                $this->template('Монтаж ограждающих сэндвич-панелей', 'facade', 'Стеновые и кровельные ограждающие конструкции склада', 'warehouse.envelope', 3900, ['material' => 0.72, 'labor' => 0.22, 'machinery' => 0.06], 0.64),
            ],
            'gates' => [
                $this->template('Монтаж промышленных ворот', 'openings', 'Ворота и погрузочные проемы склада', 'warehouse.gates', 185000, ['material' => 0.8, 'labor' => 0.16, 'machinery' => 0.04], 0.62),
            ],
            'power_supply' => [
                $this->template('Электроснабжение склада', 'electrical', 'Вводное распределение и силовые линии', 'electrical.cable', 620, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.62),
            ],
            'lighting' => [
                $this->template('Промышленное освещение склада', 'electrical', 'Светильники и линии освещения', 'warehouse.lighting', 14500, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.62),
            ],
            'fire_safety' => [
                $this->template('Пожарная сигнализация и оповещение', 'fire_safety', 'Система пожарной безопасности склада', 'warehouse.fire', 950, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.6),
            ],
            'water_sewerage' => [
                $this->template('Водоснабжение и канализация склада', 'plumbing', 'Внутренние инженерные сети склада', 'plumbing.pipe', 1150, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.6),
            ],
            'low_current' => [
                $this->template('Слаботочные системы склада', 'electrical', 'СКС, видеонаблюдение и автоматика', 'warehouse.low_current', 780, ['material' => 0.55, 'labor' => 0.42, 'machinery' => 0.03], 0.58),
            ],
            'roads' => [
                $this->template('Дороги и площадки склада', 'site', 'Подъездные пути и разгрузочные площадки', 'warehouse.roads', 3200, ['material' => 0.58, 'labor' => 0.18, 'machinery' => 0.24], 0.58),
            ],
            default => [
                $this->template('Ориентировочный строительный комплекс', 'custom', 'Работы, которые нужно уточнить по проекту', 'site.setup', 45000, ['material' => 0.35, 'labor' => 0.5, 'machinery' => 0.15], 0.48),
            ],
        };
    }

    /**
     * @param array<string, float> $mix
     * @return array<string, mixed>
     */
    private function template(
        string $name,
        string $category,
        string $description,
        string $quantityKey,
        float $fallbackUnitPrice,
        array $mix,
        float $confidence
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'quantity_key' => $quantityKey,
            'base_quantity' => 1,
            'unit' => 'ед',
            'fallback_unit_price' => $fallbackUnitPrice,
            'fallback_mix' => $mix,
            'confidence' => $confidence,
        ];
    }
}
