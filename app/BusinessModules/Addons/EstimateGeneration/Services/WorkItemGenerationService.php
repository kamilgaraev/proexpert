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
        $templates = $this->templateForPackage($packageKey, $scopeType, $quantityModel);
        $templates = $this->withSupplementalPricedTemplates($templates, $packageKey, $scopeType, $targetItemsMin);
        $workItems = [];

        foreach ($templates as $index => $template) {
            $workItem = $this->pricedWorkItem($localEstimate, $template, $quantityModel, $index);
            $resources = $this->fallbackPricingService->resourcesForTemplate($template, $workItem);
            $workItem['materials'] = $resources['materials'];
            $workItem['labor'] = $resources['labor'];
            $workItem['machinery'] = $resources['machinery'];
            $workItems[] = $workItem;
        }

        return $this->withWorkComposition($workItems, $localEstimate);
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
            'work_composition' => [],
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
    private function withWorkComposition(array $workItems, array $localEstimate): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');

        foreach ($workItems as $index => $workItem) {
            $composition = $this->detailVariants((string) $workItem['work_category']);

            $workItems[$index]['work_composition'] = $composition;
            $workItems[$index]['metadata'] = [
                ...($workItem['metadata'] ?? []),
                'package_key' => $packageKey,
                'work_composition' => $composition,
                'composition_source' => 'generation_template',
            ];
        }

        return $workItems;
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
     * @param array<int, array<string, mixed>> $templates
     * @return array<int, array<string, mixed>>
     */
    private function withSupplementalPricedTemplates(array $templates, string $packageKey, string $scopeType, int $targetItemsMin): array
    {
        $minPricedItems = min(10, max(count($templates), (int) ceil(max($targetItemsMin, count($templates)) / 7)));

        if (count($templates) >= $minPricedItems) {
            return $templates;
        }

        $knownNames = array_fill_keys(array_column($templates, 'name'), true);

        foreach ($this->supplementalTemplatePool($packageKey, $scopeType) as $template) {
            if (isset($knownNames[$template['name']])) {
                continue;
            }

            $templates[] = $template;
            $knownNames[$template['name']] = true;

            if (count($templates) >= $minPricedItems) {
                break;
            }
        }

        return $templates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supplementalTemplatePool(string $packageKey, string $scopeType): array
    {
        return match ($packageKey) {
            'earthworks' => [
                $this->template('Уплотнение основания механизированным способом', 'earthworks', 'Послойное уплотнение основания под фундамент и полы', 'earth.plan', 260, ['material' => 0.08, 'labor' => 0.32, 'machinery' => 0.6], 0.6),
                $this->template('Подсыпка инертными материалами', 'earthworks', 'Выравнивающая подсыпка основания', 'foundation.prep', 1650, ['material' => 0.66, 'labor' => 0.18, 'machinery' => 0.16], 0.58),
                $this->template('Контроль отметок основания', 'earthworks', 'Исполнительный контроль основания перед бетонированием', 'site.geodesy', 28000, ['material' => 0.04, 'labor' => 0.9, 'machinery' => 0.06], 0.58),
            ],
            'foundations', 'foundation' => [
                $this->template('Анкерные группы под колонны', 'foundation', 'Установка закладных и анкерных групп каркаса', 'warehouse.columns', 18500, ['material' => 0.72, 'labor' => 0.23, 'machinery' => 0.05], 0.58),
                $this->template('Бетонирование ростверков и балок', 'foundation', 'Бетонные элементы распределения нагрузок', 'foundation.concrete', 12400, ['material' => 0.72, 'labor' => 0.18, 'machinery' => 0.1], 0.58),
                $this->template('Уход за бетоном фундаментов', 'foundation', 'Укрытие и технологический уход за бетоном', 'foundation.concrete', 460, ['material' => 0.38, 'labor' => 0.55, 'machinery' => 0.07], 0.56),
                $this->template('Исполнительная съемка фундаментов', 'foundation', 'Контроль положения осей и отметок фундаментов', 'site.geodesy', 32000, ['material' => 0.04, 'labor' => 0.9, 'machinery' => 0.06], 0.56),
            ],
            'industrial_floor' => [
                $this->template('Устройство пароизоляции под промышленный пол', 'industrial_floor', 'Разделительный и пароизоляционный слой плиты пола', 'warehouse.floor', 220, ['material' => 0.64, 'labor' => 0.3, 'machinery' => 0.06], 0.58),
                $this->template('Контроль ровности промышленного пола', 'industrial_floor', 'Финишный контроль плоскости и швов пола', 'warehouse.floor', 180, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.56),
            ],
            'metal_frame' => [
                $this->template('Монтаж прогонов и распорок покрытия', 'metal_frame', 'Прогоны кровли и дополнительные связи', 'warehouse.beams', 78000, ['material' => 0.76, 'labor' => 0.16, 'machinery' => 0.08], 0.58),
                $this->template('Болтовые соединения металлокаркаса', 'metal_frame', 'Комплектующие и монтаж болтовых соединений', 'warehouse.frame_weight', 8200, ['material' => 0.68, 'labor' => 0.26, 'machinery' => 0.06], 0.56),
                $this->template('Антикоррозионная защита металлокаркаса', 'metal_frame', 'Грунтовка и защитное покрытие металла', 'warehouse.frame_weight', 16500, ['material' => 0.52, 'labor' => 0.42, 'machinery' => 0.06], 0.56),
                $this->template('Проверка геометрии каркаса', 'metal_frame', 'Контроль вертикальности и положения несущих элементов', 'warehouse.columns', 6200, ['material' => 0.08, 'labor' => 0.84, 'machinery' => 0.08], 0.54),
                $this->template('Монтаж вторичных несущих элементов', 'metal_frame', 'Фахверк и элементы крепления ограждений', 'warehouse.frame_weight', 34000, ['material' => 0.72, 'labor' => 0.2, 'machinery' => 0.08], 0.56),
            ],
            'envelope' => [
                $this->template('Теплые угловые узлы фасада', 'facade', 'Утепленные угловые решения стеновых панелей', 'warehouse.panel_flashings', 980, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.56),
                $this->template('Герметизация примыканий к цоколю', 'facade', 'Нижние узлы защиты стеновых панелей', 'warehouse.panel_flashings', 820, ['material' => 0.52, 'labor' => 0.42, 'machinery' => 0.06], 0.56),
            ],
            'roof' => [
                $this->template('Ограждения и проходки кровли', 'roof', 'Сервисные элементы и проходы инженерных систем через кровлю', 'roof.gutter', 2400, ['material' => 0.62, 'labor' => 0.31, 'machinery' => 0.07], 0.56),
                $this->template('Проверка герметичности кровли', 'roof', 'Контроль примыканий и водоотвода кровли', 'roof.flat_area', 140, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
            ],
            'power_supply' => [
                $this->template('Испытания кабельных линий', 'electrical', 'Измерения и протоколы по силовым кабельным линиям', 'electrical.power_lines', 95, ['material' => 0.05, 'labor' => 0.9, 'machinery' => 0.05], 0.56),
                $this->template('Маркировка и паспортизация электролиний', 'electrical', 'Маркировка кабелей, щитов и трасс', 'electrical.main_cable', 75, ['material' => 0.18, 'labor' => 0.78, 'machinery' => 0.04], 0.54),
            ],
            'lighting' => [
                $this->template('Пусконаладка системы освещения', 'electrical', 'Проверка групп освещения и аварийных линий', 'lighting.lines', 120, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
            ],
            'ventilation' => [
                $this->template('Автоматика вентиляции', 'ventilation', 'Шкаф управления и датчики вентиляционных установок', 'ventilation.air_exchange', 260, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.56),
                $this->template('Пусконаладка вентиляционных установок', 'ventilation', 'Балансировка и проверка расходов воздуха', 'ventilation.air_exchange', 190, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
            ],
            'heating' => [
                $this->template('Балансировка системы отопления', 'heating', 'Настройка контуров отопления и тепловых завес', 'heating.pipe', 220, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
            ],
            'fire_safety' => [
                $this->template('Кабельные линии пожарной автоматики', 'fire_safety', 'Линии связи пожарной сигнализации и оповещения', 'warehouse.low_current', 420, ['material' => 0.52, 'labor' => 0.43, 'machinery' => 0.05], 0.56),
                $this->template('Исполнительная документация пожарной автоматики', 'fire_safety', 'Проверка и оформление исполнительной документации', 'site.setup', 42000, ['material' => 0.05, 'labor' => 0.9, 'machinery' => 0.05], 0.54),
                $this->template('Проверка зон обнаружения пожара', 'fire_safety', 'Контроль покрытия датчиками складской и офисной зоны', 'warehouse.fire', 130, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
            ],
            'external_networks' => [
                $this->template('Земляные работы по наружным сетям', 'site', 'Траншеи и обратная засыпка наружных сетей', 'warehouse.roads', 460, ['material' => 0.12, 'labor' => 0.28, 'machinery' => 0.6], 0.54),
                $this->template('Испытания наружных инженерных сетей', 'plumbing', 'Промывка, опрессовка и проверка вводов', 'networks.external', 68000, ['material' => 0.16, 'labor' => 0.76, 'machinery' => 0.08], 0.54),
            ],
            'roads' => [
                $this->template('Разметка и организация движения на площадке', 'site', 'Разметка подъездных путей и разгрузочных зон', 'warehouse.roads', 260, ['material' => 0.46, 'labor' => 0.48, 'machinery' => 0.06], 0.54),
            ],
            default => $this->supplementalTemplatesByScope($scopeType),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supplementalTemplatesByScope(string $scopeType): array
    {
        return match ($scopeType) {
            'site' => [
                $this->template('Дополнительная механизированная планировка', 'site', 'Выравнивание площадки под рабочие зоны', 'siteworks.area', 360, ['material' => 0.12, 'labor' => 0.32, 'machinery' => 0.56], 0.54),
                $this->template('Контроль готовности площадки', 'site', 'Проверка фронта работ и исполнительные отметки', 'site.geodesy', 22000, ['material' => 0.04, 'labor' => 0.9, 'machinery' => 0.06], 0.54),
            ],
            'plumbing' => [
                $this->template('Запорная арматура инженерных сетей', 'plumbing', 'Арматура и узлы подключения трубопроводов', 'plumbing.points', 6200, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.54),
                $this->template('Испытания внутренних трубопроводов', 'plumbing', 'Опрессовка и промывка внутренних сетей', 'plumbing.pipe', 160, ['material' => 0.12, 'labor' => 0.82, 'machinery' => 0.06], 0.54),
            ],
            'electrical' => [
                $this->template('Пусконаладка электрических систем', 'electrical', 'Проверка линий, щитов и защитной автоматики', 'site.power', 56000, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.54),
                $this->template('Исполнительная маркировка электросетей', 'electrical', 'Маркировка и паспортизация электрических линий', 'electrical.main_cable', 70, ['material' => 0.16, 'labor' => 0.8, 'machinery' => 0.04], 0.54),
            ],
            default => [
                $this->template('Исполнительный контроль работ', 'custom', 'Проверка качества и объемов по разделу', 'site.setup', 28000, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.5),
                $this->template('Подготовка исполнительной документации', 'custom', 'Фиксация выполненных работ по разделу', 'site.setup', 24000, ['material' => 0.05, 'labor' => 0.9, 'machinery' => 0.05], 0.5),
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templateForPackage(string $packageKey, string $scopeType, array $quantityModel = []): array
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
                ...$this->roofTemplates($quantityModel),
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
                $this->template('Монтаж теплового узла', 'heating', 'Котельное оборудование, коллекторы и обвязка', 'heating.unit', 185000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.58),
                $this->template('Монтаж радиаторов отопления', 'heating', 'Радиаторы и арматура', 'heating.radiators', 13500, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.62),
                $this->template('Разводка труб отопления', 'heating', 'Трубная разводка системы отопления', 'heating.pipe', 980, ['material' => 0.56, 'labor' => 0.42, 'machinery' => 0.02], 0.62),
                $this->template('Воздушно-тепловые завесы ворот', 'heating', 'Завесы для ворот и входных групп склада', 'heating.air_curtains', 76000, ['material' => 0.72, 'labor' => 0.24, 'machinery' => 0.04], 0.56),
            ],
            'ventilation' => [
                $this->template('Приточно-вытяжная вентиляция корпуса', 'ventilation', 'Основная система вентиляции складской и офисной зоны', 'ventilation.air_exchange', 1450, ['material' => 0.62, 'labor' => 0.3, 'machinery' => 0.08], 0.62),
                $this->template('Воздуховоды вентиляции', 'ventilation', 'Магистральные и распределительные воздуховоды', 'ventilation.air_exchange', 820, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.6),
                $this->template('Воздухораспределители офисной зоны', 'ventilation', 'Решетки, диффузоры и клапаны офисной части', 'ventilation.office_points', 6800, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.58),
                $this->template('Вентиляционные точки склада', 'ventilation', 'Вытяжные и приточные точки складской зоны', 'ventilation.warehouse_points', 12500, ['material' => 0.6, 'labor' => 0.34, 'machinery' => 0.06], 0.58),
                $this->template('Пусконаладка вентиляции', 'ventilation', 'Балансировка и проверка расходов воздуха', 'ventilation.air_exchange', 160, ['material' => 0.08, 'labor' => 0.86, 'machinery' => 0.06], 0.56),
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
                $this->template('Наружное электроснабжение', 'electrical', 'Кабельный ввод и подключение объекта', 'networks.external', 220000, ['material' => 0.64, 'labor' => 0.18, 'machinery' => 0.18], 0.52),
                $this->template('Ливневая канализация площадки', 'sewerage', 'Водоотвод кровли и твердых покрытий', 'warehouse.roads', 1350, ['material' => 0.56, 'labor' => 0.24, 'machinery' => 0.2], 0.52),
                $this->template('Подключение наружных сетей к зданию', 'plumbing', 'Вводы сетей, футляры и узлы подключения', 'networks.external', 125000, ['material' => 0.55, 'labor' => 0.28, 'machinery' => 0.17], 0.5),
            ],
            'siteworks' => [
                $this->template('Благоустройство территории', 'site', 'Отмостка, дорожки и минимальная планировка', 'siteworks.area', 2300, ['material' => 0.56, 'labor' => 0.32, 'machinery' => 0.12], 0.54),
            ],
            'site_preparation' => [
                $this->template('Подготовка площадки склада', 'site', 'Планировка и подготовка строительной площадки', 'siteworks.area', 1600, ['material' => 0.18, 'labor' => 0.32, 'machinery' => 0.5], 0.64),
                $this->template('Временные проезды и складирование', 'site', 'Организация временной логистики на площадке', 'warehouse.roads', 1450, ['material' => 0.42, 'labor' => 0.2, 'machinery' => 0.38], 0.6),
                $this->template('Геодезическая разбивка склада', 'site', 'Разбивка осей и контроль отметок', 'site.geodesy', 42000, ['material' => 0.05, 'labor' => 0.88, 'machinery' => 0.07], 0.7),
                $this->template('Временное электроснабжение площадки', 'electrical', 'Подключение временного питания на период строительства', 'site.power', 52000, ['material' => 0.58, 'labor' => 0.34, 'machinery' => 0.08], 0.62),
                $this->template('Уборка и подготовка пятна работ', 'site', 'Очистка территории, снятие мусора и подготовка фронта работ', 'site.cleaning', 320, ['material' => 0.12, 'labor' => 0.5, 'machinery' => 0.38], 0.62),
            ],
            'foundations', 'foundations_warehouse' => [
                $this->template('Фундаменты под колонны склада', 'foundation', 'Железобетонные основания под металлокаркас', 'foundation.concrete', 11200, ['material' => 0.7, 'labor' => 0.2, 'machinery' => 0.1], 0.68),
                $this->template('Армирование фундаментов склада', 'foundation', 'Арматурные каркасы фундаментов под колонны', 'foundation.rebar', 96000, ['material' => 0.8, 'labor' => 0.18, 'machinery' => 0.02], 0.68),
                $this->template('Песчано-щебеночная подготовка фундаментов', 'foundation', 'Подготовка основания под фундаментные элементы', 'foundation.prep', 2400, ['material' => 0.68, 'labor' => 0.18, 'machinery' => 0.14], 0.66),
                $this->template('Опалубка фундаментов под колонны', 'foundation', 'Устройство и демонтаж опалубки фундаментных стаканов и ростверков', 'foundation.formwork', 1250, ['material' => 0.42, 'labor' => 0.48, 'machinery' => 0.1], 0.66),
                $this->template('Гидроизоляция фундаментных элементов', 'foundation', 'Защита бетонных поверхностей фундаментов', 'foundation.waterproofing', 780, ['material' => 0.56, 'labor' => 0.39, 'machinery' => 0.05], 0.62),
                $this->template('Бетонная подготовка под фундаментные балки', 'foundation', 'Выравнивающая бетонная подготовка под несущие элементы', 'foundation.prep', 3600, ['material' => 0.74, 'labor' => 0.16, 'machinery' => 0.1], 0.62),
            ],
            'industrial_floor' => [
                $this->template('Устройство промышленного бетонного пола', 'industrial_floor', 'Бетонная плита пола склада с упрочнением', 'warehouse.floor', 4100, ['material' => 0.68, 'labor' => 0.22, 'machinery' => 0.1], 0.7),
                $this->template('Бетон промышленной плиты пола', 'industrial_floor', 'Бетонная смесь для промышленного пола', 'warehouse.floor_concrete', 9800, ['material' => 0.76, 'labor' => 0.12, 'machinery' => 0.12], 0.7),
                $this->template('Армирование промышленного пола', 'industrial_floor', 'Арматурные сетки промышленной плиты', 'warehouse.floor_rebar', 92000, ['material' => 0.82, 'labor' => 0.16, 'machinery' => 0.02], 0.68),
                $this->template('Песчано-щебеночное основание пола', 'industrial_floor', 'Подстилающие слои под промышленный пол', 'warehouse.floor', 1250, ['material' => 0.62, 'labor' => 0.18, 'machinery' => 0.2], 0.66),
                $this->template('Упрочненный верхний слой пола', 'industrial_floor', 'Топпинг и затирка промышленного пола', 'warehouse.floor_hardener', 880, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.66),
                $this->template('Нарезка деформационных швов пола', 'industrial_floor', 'Швы промышленного пола с герметизацией', 'warehouse.floor_joints', 640, ['material' => 0.42, 'labor' => 0.5, 'machinery' => 0.08], 0.64),
                $this->template('Обеспыливание промышленного пола', 'industrial_floor', 'Финишная пропитка и обеспыливание бетонного пола', 'warehouse.floor', 520, ['material' => 0.5, 'labor' => 0.45, 'machinery' => 0.05], 0.62),
            ],
            'metal_frame' => [
                $this->template('Монтаж металлокаркаса склада', 'metal_frame', 'Колонны, балки и связи каркаса', 'warehouse.frame_weight', 155000, ['material' => 0.78, 'labor' => 0.14, 'machinery' => 0.08], 0.66),
                $this->template('Монтаж металлических колонн', 'metal_frame', 'Установка колонн каркаса склада', 'warehouse.columns', 24500, ['material' => 0.72, 'labor' => 0.18, 'machinery' => 0.1], 0.64),
                $this->template('Монтаж балок и ферм покрытия', 'metal_frame', 'Несущие балки, фермы и прогоны', 'warehouse.beams', 132000, ['material' => 0.78, 'labor' => 0.14, 'machinery' => 0.08], 0.64),
                $this->template('Монтаж связей и распорок каркаса', 'metal_frame', 'Вертикальные и горизонтальные связи металлокаркаса', 'warehouse.frame_weight', 26000, ['material' => 0.7, 'labor' => 0.22, 'machinery' => 0.08], 0.62),
                $this->template('Огнезащита металлоконструкций', 'metal_frame', 'Нанесение огнезащитного покрытия на каркас', 'warehouse.frame_weight', 18500, ['material' => 0.52, 'labor' => 0.42, 'machinery' => 0.06], 0.58),
            ],
            'envelope' => [
                $this->template('Монтаж ограждающих сэндвич-панелей', 'facade', 'Стеновые ограждающие конструкции склада без кровельного покрытия', 'warehouse.envelope', 3900, ['material' => 0.72, 'labor' => 0.22, 'machinery' => 0.06], 0.64),
                $this->template('Монтаж стеновых сэндвич-панелей', 'facade', 'Стеновые панели по наружному контуру', 'warehouse.wall_panels', 3600, ['material' => 0.74, 'labor' => 0.21, 'machinery' => 0.05], 0.66),
                $this->template('Монтаж доборных элементов панелей', 'facade', 'Углы, нащельники, примыкания и фасонные элементы', 'warehouse.panel_flashings', 720, ['material' => 0.62, 'labor' => 0.34, 'machinery' => 0.04], 0.62),
                $this->template('Герметизация стыков сэндвич-панелей', 'facade', 'Уплотнение и герметизация примыканий ограждений', 'warehouse.panel_flashings', 430, ['material' => 0.48, 'labor' => 0.48, 'machinery' => 0.04], 0.6),
                $this->template('Монтаж цокольных примыканий ограждений', 'facade', 'Нижние примыкания стеновых панелей к основанию', 'warehouse.panel_flashings', 560, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.6),
                $this->template('Уплотнение примыканий ворот и фасада', 'facade', 'Теплые узлы примыкания панелей к воротам и входам', 'warehouse.gates', 18500, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.58),
            ],
            'gates' => [
                $this->template('Монтаж промышленных ворот', 'openings', 'Ворота и погрузочные проемы склада', 'warehouse.gates', 185000, ['material' => 0.8, 'labor' => 0.16, 'machinery' => 0.04], 0.62),
                $this->template('Обрамление проемов ворот', 'openings', 'Металлическое обрамление проемов под ворота', 'warehouse.gates', 36000, ['material' => 0.7, 'labor' => 0.24, 'machinery' => 0.06], 0.6),
                $this->template('Монтаж погрузочных узлов', 'openings', 'Доковые элементы и узлы погрузки', 'warehouse.loading_nodes', 125000, ['material' => 0.76, 'labor' => 0.18, 'machinery' => 0.06], 0.58),
                $this->template('Автоматика промышленных ворот', 'openings', 'Приводы, управление и пусконаладка ворот', 'warehouse.gates', 42000, ['material' => 0.68, 'labor' => 0.3, 'machinery' => 0.02], 0.56),
            ],
            'power_supply' => [
                $this->template('Электроснабжение склада', 'electrical', 'Вводное распределение и магистральные кабельные линии', 'electrical.main_cable', 620, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.62),
                $this->template('Вводно-распределительное устройство', 'electrical', 'Главный вводной щит объекта', 'site.power', 145000, ['material' => 0.76, 'labor' => 0.21, 'machinery' => 0.03], 0.62),
                $this->template('Кабельные лотки и трассы', 'electrical', 'Лотки, короба и несущие системы кабельных линий', 'electrical.trays', 420, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.62),
                $this->template('Силовые кабельные линии', 'electrical', 'Прокладка силовых кабелей к оборудованию', 'electrical.power_lines', 520, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.62),
                $this->template('Заземление и уравнивание потенциалов', 'electrical', 'Контур заземления и подключение металлоконструкций', 'electrical.grounding', 180, ['material' => 0.52, 'labor' => 0.42, 'machinery' => 0.06], 0.58),
                $this->template('Щиты распределения складской зоны', 'electrical', 'Распределительные щиты по складским линиям и воротам', 'site.power', 96000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.58),
                $this->template('Кабельные подключения ворот и оборудования', 'electrical', 'Подключение приводов ворот и инженерного оборудования', 'electrical.power_lines', 480, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.58),
            ],
            'lighting' => [
                $this->template('Промышленное освещение склада', 'electrical', 'Светильники и линии освещения', 'warehouse.lighting', 14500, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.62),
                $this->template('Монтаж линий освещения склада', 'electrical', 'Кабельные линии групп освещения', 'lighting.lines', 260, ['material' => 0.56, 'labor' => 0.4, 'machinery' => 0.04], 0.6),
                $this->template('Аварийное освещение', 'electrical', 'Светильники аварийного и эвакуационного освещения', 'warehouse.lighting', 3600, ['material' => 0.68, 'labor' => 0.29, 'machinery' => 0.03], 0.58),
                $this->template('Щиты управления освещением', 'electrical', 'Групповые щиты и коммутация освещения', 'site.power', 54000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.58),
                $this->template('Наружное освещение разгрузочной зоны', 'electrical', 'Светильники и линии наружных площадок', 'warehouse.roads', 740, ['material' => 0.62, 'labor' => 0.32, 'machinery' => 0.06], 0.56),
                $this->template('Освещение офисной зоны', 'electrical', 'Световые линии офисов и переговорной', 'office.electrical_points', 1850, ['material' => 0.5, 'labor' => 0.47, 'machinery' => 0.03], 0.56),
            ],
            'fire_safety' => [
                $this->template('Пожарная сигнализация и оповещение', 'fire_safety', 'Система пожарной безопасности склада', 'warehouse.fire', 950, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.6),
                $this->template('Датчики пожарной сигнализации', 'fire_safety', 'Адресные датчики и извещатели', 'warehouse.fire', 320, ['material' => 0.68, 'labor' => 0.3, 'machinery' => 0.02], 0.58),
                $this->template('Система оповещения о пожаре', 'fire_safety', 'Оповещатели, табло и линии управления', 'warehouse.fire', 280, ['material' => 0.64, 'labor' => 0.33, 'machinery' => 0.03], 0.58),
                $this->template('Пожарные кабельные линии', 'fire_safety', 'Огнестойкие кабели пожарных систем', 'warehouse.low_current', 520, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.56),
                $this->template('Пусконаладка пожарной автоматики', 'fire_safety', 'Проверка сценариев и исполнительных устройств', 'site.setup', 68000, ['material' => 0.1, 'labor' => 0.86, 'machinery' => 0.04], 0.56),
            ],
            'water_sewerage' => [
                $this->template('Водоснабжение и канализация склада', 'plumbing', 'Внутренние инженерные сети склада', 'plumbing.pipe', 1150, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.6),
                $this->template('Магистрали хозяйственно-питьевого водоснабжения', 'plumbing', 'Трубопроводы водоснабжения объекта', 'plumbing.pipe', 980, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.6),
                $this->template('Внутренняя канализация корпуса', 'sewerage', 'Трубопроводы внутренней канализации', 'sewerage.pipe', 870, ['material' => 0.56, 'labor' => 0.39, 'machinery' => 0.05], 0.6),
                $this->template('Подключение сантехнических точек', 'plumbing', 'Подключение приборов и арматуры', 'sanitary.points', 9200, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.58),
                $this->template('Гидравлические испытания сетей', 'plumbing', 'Промывка и испытание внутренних трубопроводов', 'plumbing.pipe', 180, ['material' => 0.2, 'labor' => 0.74, 'machinery' => 0.06], 0.56),
            ],
            'low_current' => [
                $this->template('Слаботочные системы склада', 'electrical', 'СКС, видеонаблюдение и автоматика', 'warehouse.low_current', 780, ['material' => 0.55, 'labor' => 0.42, 'machinery' => 0.03], 0.58),
                $this->template('Структурированная кабельная сеть', 'electrical', 'СКС офисной и складской зоны', 'office.network_points', 6200, ['material' => 0.62, 'labor' => 0.35, 'machinery' => 0.03], 0.58),
                $this->template('Видеонаблюдение склада и офиса', 'electrical', 'Камеры, линии и коммутация видеонаблюдения', 'warehouse.low_current', 680, ['material' => 0.64, 'labor' => 0.33, 'machinery' => 0.03], 0.56),
                $this->template('Контроль доступа', 'electrical', 'Точки доступа входной группы и служебных помещений', 'entrance.group', 72000, ['material' => 0.68, 'labor' => 0.29, 'machinery' => 0.03], 0.54),
            ],
            'entrance_group' => [
                $this->template('Устройство входной группы', 'openings', 'Тамбур, входные двери и примыкания', 'entrance.group', 185000, ['material' => 0.74, 'labor' => 0.22, 'machinery' => 0.04], 0.58),
                $this->template('Монтаж наружных дверей входной группы', 'openings', 'Входные металлические и алюминиевые двери', 'entrance.group', 96000, ['material' => 0.78, 'labor' => 0.2, 'machinery' => 0.02], 0.58),
                $this->template('Отделка тамбура входной группы', 'finishing', 'Плитка, окраска и потолок тамбура', 'entrance.group', 74000, ['material' => 0.56, 'labor' => 0.4, 'machinery' => 0.04], 0.56),
            ],
            'office_partitions' => [
                $this->template('Монтаж офисных перегородок', 'masonry', 'Перегородки кабинетов и переговорных', 'office.partitions', 1850, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.62),
                $this->template('Устройство дверных проемов в перегородках', 'masonry', 'Проемы и усиления офисных перегородок', 'office.doors', 8400, ['material' => 0.54, 'labor' => 0.42, 'machinery' => 0.04], 0.58),
                $this->template('Звукоизоляция офисных перегородок', 'masonry', 'Минераловатное заполнение перегородок', 'office.partitions', 620, ['material' => 0.68, 'labor' => 0.29, 'machinery' => 0.03], 0.56),
                $this->template('Шпаклевка офисных перегородок', 'finishing', 'Подготовка перегородок под окраску', 'office.partitions', 420, ['material' => 0.36, 'labor' => 0.6, 'machinery' => 0.04], 0.58),
            ],
            'office_finishing' => [
                $this->template('Стяжка пола офисной зоны', 'finishing', 'Выравнивающая стяжка офисных помещений', 'office.floor', 980, ['material' => 0.48, 'labor' => 0.45, 'machinery' => 0.07], 0.62),
                $this->template('Чистовое покрытие пола офиса', 'finishing', 'Коммерческий ламинат или ПВХ-плитка', 'office.floor_finish', 1750, ['material' => 0.64, 'labor' => 0.34, 'machinery' => 0.02], 0.6),
                $this->template('Подвесной потолок офисной зоны', 'finishing', 'Потолочная система офисных помещений', 'office.ceiling', 1450, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.6),
                $this->template('Окраска стен офисной зоны', 'finishing', 'Финишная окраска стен и перегородок', 'office.paint', 520, ['material' => 0.42, 'labor' => 0.55, 'machinery' => 0.03], 0.58),
                $this->template('Установка офисных дверей', 'openings', 'Дверные блоки кабинетов и переговорных', 'office.doors', 24000, ['material' => 0.76, 'labor' => 0.22, 'machinery' => 0.02], 0.58),
                $this->template('Электроточки офисной зоны', 'electrical', 'Розетки, выключатели и рабочие места офиса', 'office.electrical_points', 2100, ['material' => 0.5, 'labor' => 0.47, 'machinery' => 0.03], 0.58),
            ],
            'sanitary_rooms' => [
                $this->template('Устройство перегородок санузлов', 'masonry', 'Перегородки мокрых зон', 'sanitary.rooms', 42000, ['material' => 0.56, 'labor' => 0.4, 'machinery' => 0.04], 0.58),
                $this->template('Гидроизоляция санузлов', 'finishing', 'Обмазочная гидроизоляция мокрых зон', 'sanitary.tile', 680, ['material' => 0.52, 'labor' => 0.44, 'machinery' => 0.04], 0.58),
                $this->template('Плиточная отделка санузлов', 'finishing', 'Плитка пола и стен мокрых зон', 'sanitary.tile', 3600, ['material' => 0.55, 'labor' => 0.43, 'machinery' => 0.02], 0.6),
                $this->template('Монтаж сантехнических приборов', 'plumbing', 'Унитазы, раковины, смесители и арматура', 'sanitary.points', 14500, ['material' => 0.64, 'labor' => 0.34, 'machinery' => 0.02], 0.58),
                $this->template('Вентиляция санузлов', 'ventilation', 'Вытяжные точки мокрых зон', 'sanitary.rooms', 18500, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.56),
            ],
            'server_room' => [
                $this->template('Подготовка серверной', 'electrical', 'Отдельное помещение серверной или телекоммуникационного узла', 'server.room', 98000, ['material' => 0.56, 'labor' => 0.38, 'machinery' => 0.06], 0.56),
                $this->template('Электропитание серверной', 'electrical', 'Выделенные линии и щит серверной', 'server.room', 125000, ['material' => 0.66, 'labor' => 0.31, 'machinery' => 0.03], 0.56),
                $this->template('Слаботочный шкаф и коммутация', 'electrical', 'Шкаф, патч-панели и коммутационное оборудование', 'server.room', 145000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.54),
                $this->template('Кондиционирование серверной', 'ventilation', 'Охлаждение серверной зоны', 'server.room', 115000, ['material' => 0.7, 'labor' => 0.25, 'machinery' => 0.05], 0.52),
            ],
            'roads' => [
                $this->template('Дороги и площадки склада', 'site', 'Подъездные пути и разгрузочные площадки', 'warehouse.roads', 3200, ['material' => 0.58, 'labor' => 0.18, 'machinery' => 0.24], 0.58),
                $this->template('Щебеночное основание проездов', 'site', 'Подготовка основания для грузового транспорта', 'warehouse.roads', 1450, ['material' => 0.62, 'labor' => 0.16, 'machinery' => 0.22], 0.58),
                $this->template('Асфальтобетонное покрытие площадок', 'site', 'Покрытие подъездов и разгрузочных зон', 'warehouse.roads', 2850, ['material' => 0.68, 'labor' => 0.12, 'machinery' => 0.2], 0.58),
                $this->template('Бортовой камень и водоотвод площадок', 'site', 'Обрамление покрытий и поверхностный водоотвод', 'warehouse.roads', 740, ['material' => 0.58, 'labor' => 0.28, 'machinery' => 0.14], 0.54),
            ],
            default => [
                $this->template('Ориентировочный строительный комплекс', 'custom', 'Работы, которые нужно уточнить по проекту', 'site.setup', 45000, ['material' => 0.35, 'labor' => 0.5, 'machinery' => 0.15], 0.48),
            ],
        };
    }

    /**
     * @param array<string, mixed> $quantityModel
     * @return array<int, array<string, mixed>>
     */
    private function roofTemplates(array $quantityModel): array
    {
        $features = is_array($quantityModel['features'] ?? null) ? $quantityModel['features'] : [];

        if (($features['roof_type'] ?? null) === 'flat') {
            return [
                $this->template('Устройство плоской кровли по профнастилу', 'roof', 'Основание и кровельный пирог плоской кровли', 'roof.flat_area', 2450, ['material' => 0.68, 'labor' => 0.24, 'machinery' => 0.08], 0.68),
                $this->template('Пароизоляция плоской кровли', 'roof', 'Пароизоляционный слой кровельного пирога', 'roof.flat_area', 420, ['material' => 0.62, 'labor' => 0.34, 'machinery' => 0.04], 0.66),
                $this->template('Утепление плоской кровли', 'roof', 'Теплоизоляция кровли промышленного корпуса', 'roof.flat_area', 1650, ['material' => 0.72, 'labor' => 0.24, 'machinery' => 0.04], 0.66),
                $this->template('Гидроизоляционный ковер плоской кровли', 'roof', 'Рулонная или мембранная гидроизоляция кровли', 'roof.flat_area', 1850, ['material' => 0.66, 'labor' => 0.3, 'machinery' => 0.04], 0.66),
                $this->template('Парапеты и примыкания плоской кровли', 'roof', 'Узлы примыканий, парапеты и проходки', 'roof.gutter', 2200, ['material' => 0.62, 'labor' => 0.34, 'machinery' => 0.04], 0.62),
                $this->template('Внутренний водосток плоской кровли', 'roof', 'Воронки, стояки и водоотвод с плоской кровли', 'roof.gutter', 1750, ['material' => 0.64, 'labor' => 0.31, 'machinery' => 0.05], 0.6),
            ];
        }

        return [
            $this->template('Монтаж стропильной системы', 'roof', 'Несущая деревянная конструкция кровли', 'roof.area', 1450, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.7),
            $this->template('Утепление кровли 200 мм', 'roof', 'Теплоизоляция кровельного пирога', 'roof.area', 1350, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.7),
            $this->template('Монтаж металлочерепицы', 'roof', 'Финишное кровельное покрытие', 'roof.area', 1850, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.72),
            $this->template('Водосточная система кровли', 'roof', 'Желоба, трубы и крепления', 'roof.gutter', 1250, ['material' => 0.65, 'labor' => 0.32, 'machinery' => 0.03], 0.66),
        ];
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
