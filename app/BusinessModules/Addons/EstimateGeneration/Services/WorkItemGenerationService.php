<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class WorkItemGenerationService
{
    public function __construct(
        protected MarketFallbackPricingService $fallbackPricingService,
    ) {}

    /**
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $analysis
     * @return array<int, array<string, mixed>>
     */
    public function build(array $localEstimate, array $analysis): array
    {
        $scopeType = (string) ($localEstimate['scope_type'] ?? 'custom');
        $template = $this->expandTemplateForTarget(
            $this->templateForScope($scopeType),
            (int) ($localEstimate['target_items_min'] ?? 0)
        );
        $area = (float) ($analysis['object']['area'] ?? 0);
        $workItems = [];

        foreach ($template as $index => $item) {
            $quantity = $this->quantityForTemplate($item, $area);
            $workItem = [
                'key' => $localEstimate['key'] . '-work-' . ($index + 1),
                'name' => $item['name'],
                'work_category' => $item['category'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => $quantity,
                'quantity_formula' => $area > 0 ? (string) ($item['quantity_formula'] ?? 'Расчет от площади объекта') : 'Ориентировочный шаблон по конструктиву',
                'quantity_basis' => $area > 0 ? 'Площадь объекта ' . $area . ' м2 и тип конструктивного блока' : 'Текстовые признаки в описании и документах',
                'work_cost' => 0,
                'materials_cost' => 0,
                'machinery_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => 0,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'other_resources' => [],
                'source_refs' => $localEstimate['source_refs'],
                'confidence' => $item['confidence'],
                'validation_flags' => ['market_price_used'],
                'price_source' => 'market_estimate',
            ];
            $resources = $this->fallbackPricingService->resourcesForTemplate($item, $workItem);
            $workItem['materials'] = $resources['materials'];
            $workItem['labor'] = $resources['labor'];
            $workItem['machinery'] = $resources['machinery'];
            $workItems[] = $workItem;
        }

        return $workItems;
    }

    /**
     * @param array<int, array<string, mixed>> $template
     * @return array<int, array<string, mixed>>
     */
    private function expandTemplateForTarget(array $template, int $targetItemsMin): array
    {
        if ($targetItemsMin <= 0 || count($template) >= $targetItemsMin) {
            return $template;
        }

        $expanded = [];
        $baseCount = count($template);
        $perBase = max((int) ceil($targetItemsMin / max($baseCount, 1)), 1);

        foreach ($template as $baseIndex => $item) {
            $sourceVariants = $this->detailVariants((string) ($item['category'] ?? 'work'));
            $variants = [];

            for ($variantIndex = 0; $variantIndex < $perBase; $variantIndex++) {
                $name = $sourceVariants[$variantIndex % count($sourceVariants)];
                $cycle = intdiv($variantIndex, count($sourceVariants)) + 1;
                $variants[] = $cycle > 1 ? $name . ' этап ' . $cycle : $name;
            }

            $share = 1 / max(count($variants), 1);

            foreach ($variants as $variantIndex => $variant) {
                $expanded[] = [
                    ...$item,
                    'name' => $item['name'] . ': ' . $variant,
                    'description' => trim((string) ($item['description'] ?? '') . '. ' . $variant),
                    'base_quantity' => round((float) ($item['base_quantity'] ?? 0) * $share, 8),
                    'quantity_formula' => trim((string) ($item['quantity_formula'] ?? 'Расчет от площади объекта') . ', детализация ' . ($variantIndex + 1)),
                    'template_parent_index' => $baseIndex,
                    'template_variant' => $variant,
                ];

                if (count($expanded) >= $targetItemsMin) {
                    break 2;
                }
            }
        }

        return $expanded;
    }

    /**
     * @return array<int, string>
     */
    private function detailVariants(string $category): array
    {
        $common = [
            'подготовка фронта работ',
            'поставка основных материалов',
            'подготовка основания',
            'основной монтаж',
            'доборные элементы',
            'крепление и примыкания',
            'контроль качества',
            'уборка и сдача участка',
        ];

        return match ($category) {
            'earthworks' => ['разметка', 'разработка грунта', 'погрузка грунта', 'вывоз грунта', 'планировка дна', 'обратная засыпка', 'уплотнение', 'контроль отметок'],
            'base' => ['геотекстиль', 'песчаный слой', 'щебеночный слой', 'послойное уплотнение', 'выравнивание', 'контроль толщины', 'увлажнение', 'приемка основания'],
            'reinforcement' => ['заготовка арматуры', 'вязка каркасов', 'установка фиксаторов', 'монтаж выпусков', 'усиление углов', 'контроль защитного слоя', 'приемка арматуры', 'доборные стержни'],
            'concrete' => ['подготовка опалубки', 'приемка бетонной смеси', 'укладка бетона', 'вибрирование', 'уход за бетоном', 'разборка опалубки', 'контроль поверхности', 'заделка дефектов'],
            'waterproofing' => ['очистка основания', 'грунтовка', 'первый слой изоляции', 'второй слой изоляции', 'примыкания', 'защита изоляции', 'контроль сплошности', 'локальный ремонт'],
            'masonry' => ['разметка осей', 'первый ряд', 'основная кладка', 'армирование рядов', 'перемычки', 'примыкания', 'подрезка блоков', 'контроль геометрии'],
            'finishing' => ['подготовка поверхности', 'грунтование', 'маячные работы', 'основной слой', 'выравнивание', 'шлифовка', 'примыкания', 'финишный контроль'],
            'frame' => ['поставка конструкций', 'разметка', 'монтаж несущих элементов', 'временное крепление', 'постоянное крепление', 'раскосы', 'антисептирование', 'контроль геометрии'],
            'insulation' => ['подготовка основания', 'пароизоляция', 'укладка утеплителя', 'дополнительный слой утеплителя', 'герметизация стыков', 'крепление', 'контроль мостиков холода', 'защитный слой'],
            'covering' => ['обрешетка', 'подкладочный слой', 'основное покрытие', 'коньковые элементы', 'ендовы', 'карнизные элементы', 'примыкания', 'водосточная подготовка'],
            'electrical' => ['проектная разметка', 'штробление и проходки', 'прокладка линий', 'установка коробок', 'подключение', 'маркировка', 'измерения', 'пусконаладка'],
            'plumbing' => ['разметка трасс', 'проходки', 'прокладка труб', 'запорная арматура', 'теплоизоляция', 'крепление', 'опрессовка', 'промывка'],
            'heating' => ['разметка трасс', 'монтаж труб', 'монтаж приборов', 'обвязка оборудования', 'арматура', 'опрессовка', 'балансировка', 'пусконаладка'],
            'ventilation' => ['разметка трасс', 'монтаж каналов', 'крепления', 'проходки', 'решетки и клапаны', 'изоляция', 'проверка тяги', 'регулировка'],
            'openings' => ['подготовка проема', 'установка блока', 'крепление', 'заполнение швов', 'отливы и подоконники', 'фурнитура', 'регулировка', 'герметизация'],
            default => $common,
        };
    }

    /**
     * @param array<string, mixed> $template
     */
    private function quantityForTemplate(array $template, float $area): float
    {
        $baseQuantity = (float) $template['base_quantity'];
        $mode = (string) ($template['quantity_mode'] ?? 'area_factor');

        if ($area <= 0) {
            return round($baseQuantity, 2);
        }

        return match ($mode) {
            'area' => round($area * $baseQuantity, 2),
            'fixed' => round($baseQuantity, 2),
            default => round($baseQuantity * max($area / 100, 1), 2),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templateForScope(string $scopeType): array
    {
        $templates = [
            'foundation' => [
                $this->template('Разработка грунта под ленточный фундамент', 'earthworks', 'Подготовка траншей и вывоз лишнего грунта', 'м3', 0.28, 850, ['material' => 0.05, 'labor' => 0.45, 'machinery' => 0.5], 0.74, 'area'),
                $this->template('Устройство песчаной подготовки', 'base', 'Песчаная подушка с послойным уплотнением', 'м3', 0.08, 2400, ['material' => 0.65, 'labor' => 0.2, 'machinery' => 0.15], 0.72, 'area'),
                $this->template('Армирование фундаментной ленты', 'reinforcement', 'Монтаж арматурных каркасов', 'т', 0.008, 92000, ['material' => 0.78, 'labor' => 0.2, 'machinery' => 0.02], 0.72, 'area'),
                $this->template('Бетонирование фундаментной ленты В22.5', 'concrete', 'Укладка бетона в фундаментные конструкции', 'м3', 0.12, 9800, ['material' => 0.72, 'labor' => 0.18, 'machinery' => 0.1], 0.76, 'area'),
                $this->template('Гидроизоляция фундаментных поверхностей', 'waterproofing', 'Защита конструкций от влаги', 'м2', 0.45, 720, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.68, 'area'),
            ],
            'walls' => [
                $this->template('Кладка наружных стен из газобетона D500 400 мм', 'masonry', 'Наружные стены жилого дома', 'м3', 0.23, 7600, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.74, 'area'),
                $this->template('Кладка внутренних перегородок', 'masonry', 'Внутренние перегородки и простенки', 'м2', 0.55, 1450, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.7, 'area'),
                $this->template('Штукатурка стен по газобетону', 'finishing', 'Подготовка стен под чистовую отделку', 'м2', 2.4, 780, ['material' => 0.35, 'labor' => 0.6, 'machinery' => 0.05], 0.67, 'area'),
            ],
            'slabs' => [
                $this->template('Устройство монолитного перекрытия', 'concrete', 'Опалубка, армирование и бетонирование перекрытия', 'м2', 1, 7800, ['material' => 0.7, 'labor' => 0.22, 'machinery' => 0.08], 0.7, 'area'),
                $this->template('Монтаж лестничных и доборных элементов перекрытия', 'structural', 'Локальные элементы перекрытий и проемов', 'компл', 1, 115000, ['material' => 0.62, 'labor' => 0.28, 'machinery' => 0.1], 0.55, 'fixed'),
            ],
            'roof' => [
                $this->template('Монтаж стропильной системы', 'frame', 'Несущая деревянная конструкция кровли', 'м2', 1.25, 1450, ['material' => 0.58, 'labor' => 0.36, 'machinery' => 0.06], 0.7, 'area'),
                $this->template('Утепление кровли 200 мм', 'insulation', 'Теплоизоляция кровельного пирога', 'м2', 1.25, 1350, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.7, 'area'),
                $this->template('Монтаж металлочерепицы с доборными элементами', 'covering', 'Финишное кровельное покрытие', 'м2', 1.25, 1850, ['material' => 0.68, 'labor' => 0.28, 'machinery' => 0.04], 0.72, 'area'),
            ],
            'openings' => [
                $this->template('Установка окон ПВХ с двухкамерным стеклопакетом', 'openings', 'Оконные блоки жилого дома', 'м2', 0.18, 14500, ['material' => 0.78, 'labor' => 0.2, 'machinery' => 0.02], 0.62, 'area'),
                $this->template('Установка входной металлической двери', 'openings', 'Входная дверь с монтажом', 'шт', 1, 52000, ['material' => 0.82, 'labor' => 0.18, 'machinery' => 0], 0.68, 'fixed'),
            ],
            'electrical' => [
                $this->template('Монтаж электрического щита', 'electrical', 'Вводной щит и автоматика', 'компл', 1, 65000, ['material' => 0.72, 'labor' => 0.26, 'machinery' => 0.02], 0.66, 'fixed'),
                $this->template('Прокладка кабельных линий', 'electrical', 'Кабельные линии по дому', 'м', 5.5, 390, ['material' => 0.55, 'labor' => 0.43, 'machinery' => 0.02], 0.64, 'area'),
                $this->template('Монтаж розеток, выключателей и световых точек', 'electrical', 'Финишные электромонтажные точки', 'точка', 0.55, 1850, ['material' => 0.45, 'labor' => 0.53, 'machinery' => 0.02], 0.62, 'area'),
            ],
            'plumbing' => [
                $this->template('Разводка водоснабжения и канализации', 'plumbing', 'Внутридомовые трубы и подключение приборов', 'точка', 0.16, 9200, ['material' => 0.58, 'labor' => 0.38, 'machinery' => 0.04], 0.62, 'area'),
                $this->template('Устройство септика или локальной канализации', 'plumbing', 'Наружная канализация для дома', 'компл', 1, 260000, ['material' => 0.68, 'labor' => 0.2, 'machinery' => 0.12], 0.54, 'fixed'),
                $this->template('Устройство скважины или колодца', 'plumbing', 'Источник водоснабжения', 'компл', 1, 230000, ['material' => 0.5, 'labor' => 0.18, 'machinery' => 0.32], 0.5, 'fixed'),
            ],
            'heating' => [
                $this->template('Монтаж газового котла и обвязки', 'heating', 'Котельное оборудование и подключение', 'компл', 1, 185000, ['material' => 0.72, 'labor' => 0.25, 'machinery' => 0.03], 0.58, 'fixed'),
                $this->template('Монтаж радиаторов отопления', 'heating', 'Радиаторы и арматура', 'шт', 0.08, 13500, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.62, 'area'),
                $this->template('Разводка труб отопления', 'heating', 'Трубная разводка системы отопления', 'м', 1.2, 980, ['material' => 0.56, 'labor' => 0.42, 'machinery' => 0.02], 0.62, 'area'),
            ],
            'ventilation' => [
                $this->template('Устройство естественной вентиляции', 'ventilation', 'Вентиляционные каналы и выводы', 'компл', 1, 65000, ['material' => 0.55, 'labor' => 0.4, 'machinery' => 0.05], 0.58, 'fixed'),
                $this->template('Монтаж приточных клапанов', 'ventilation', 'Приточные клапаны в жилых помещениях', 'шт', 0.06, 4500, ['material' => 0.7, 'labor' => 0.28, 'machinery' => 0.02], 0.6, 'area'),
            ],
            'rough_finishing' => [
                $this->template('Устройство стяжки пола', 'finishing', 'Черновое выравнивание пола', 'м2', 1, 1050, ['material' => 0.48, 'labor' => 0.45, 'machinery' => 0.07], 0.66, 'area'),
                $this->template('Штукатурка внутренних стен', 'finishing', 'Черновая подготовка стен', 'м2', 2.2, 760, ['material' => 0.35, 'labor' => 0.6, 'machinery' => 0.05], 0.66, 'area'),
            ],
            'finish_finishing' => [
                $this->template('Укладка ламината', 'finishing', 'Бюджетное напольное покрытие', 'м2', 0.65, 1550, ['material' => 0.64, 'labor' => 0.34, 'machinery' => 0.02], 0.58, 'area'),
                $this->template('Укладка плитки в санузлах', 'finishing', 'Плиточные работы во влажных помещениях', 'м2', 0.18, 3200, ['material' => 0.55, 'labor' => 0.43, 'machinery' => 0.02], 0.55, 'area'),
                $this->template('Оклейка обоев под покраску и окраска', 'finishing', 'Финишная отделка стен', 'м2', 1.8, 980, ['material' => 0.48, 'labor' => 0.5, 'machinery' => 0.02], 0.56, 'area'),
            ],
            'custom' => [
                $this->template('Подготовительные строительные работы', 'preparation', 'Организация и подготовка фронта работ', 'компл', 1, 45000, ['material' => 0.25, 'labor' => 0.65, 'machinery' => 0.1], 0.5, 'fixed'),
            ],
        ];

        return $templates[$scopeType] ?? $templates['custom'];
    }

    /**
     * @param array<string, float> $mix
     * @return array<string, mixed>
     */
    private function template(
        string $name,
        string $category,
        string $description,
        string $unit,
        float $baseQuantity,
        float $fallbackUnitPrice,
        array $mix,
        float $confidence,
        string $quantityMode
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'unit' => $unit,
            'base_quantity' => $baseQuantity,
            'fallback_unit_price' => $fallbackUnitPrice,
            'fallback_mix' => $mix,
            'confidence' => $confidence,
            'quantity_mode' => $quantityMode,
        ];
    }
}
