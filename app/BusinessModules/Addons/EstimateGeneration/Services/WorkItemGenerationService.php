<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class WorkItemGenerationService
{
    public function build(array $localEstimate, array $analysis): array
    {
        $template = $this->templateForScope($localEstimate['scope_type']);
        $area = (float) ($analysis['object']['area'] ?? 0);
        $multiplier = $area > 0 ? max($area / 100, 1) : 1;
        $workItems = [];

        foreach ($template as $index => $item) {
            $quantity = round(($item['base_quantity'] * $multiplier), 2);
            $workItems[] = [
                'key' => $localEstimate['key'] . '-work-' . ($index + 1),
                'name' => $item['name'],
                'work_category' => $item['category'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'quantity' => $quantity,
                'quantity_formula' => $area > 0 ? 'Базовый коэффициент x площадь/100' : 'Эвристический шаблон по конструктиву',
                'quantity_basis' => $area > 0 ? 'Площадь объекта ' . $area . ' м2 и тип конструктивного блока' : 'Текстовые признаки в описании и чертежах',
                'work_cost' => 0,
                'materials_cost' => 0,
                'machinery_cost' => 0,
                'labor_cost' => 0,
                'total_cost' => 0,
                'materials' => [],
                'labor' => [],
                'machinery' => [],
                'source_refs' => $localEstimate['source_refs'],
                'confidence' => $item['confidence'],
                'validation_flags' => [],
            ];
        }

        return $workItems;
    }

    protected function templateForScope(string $scopeType): array
    {
        $templates = [
            'foundation' => [
                ['name' => 'Разработка грунта', 'category' => 'earthworks', 'description' => 'Подготовка котлована и траншей', 'unit' => 'м3', 'base_quantity' => 18, 'confidence' => 0.76],
                ['name' => 'Устройство песчаной подготовки', 'category' => 'base', 'description' => 'Выравнивающий и дренирующий слой', 'unit' => 'м3', 'base_quantity' => 6, 'confidence' => 0.72],
                ['name' => 'Армирование фундаментов', 'category' => 'reinforcement', 'description' => 'Монтаж арматурных каркасов', 'unit' => 'т', 'base_quantity' => 0.8, 'confidence' => 0.74],
                ['name' => 'Бетонирование фундаментов', 'category' => 'concrete', 'description' => 'Устройство фундаментных конструкций', 'unit' => 'м3', 'base_quantity' => 12, 'confidence' => 0.79],
                ['name' => 'Гидроизоляция фундаментных поверхностей', 'category' => 'waterproofing', 'description' => 'Защита конструкций от влаги', 'unit' => 'м2', 'base_quantity' => 45, 'confidence' => 0.71],
            ],
            'walls' => [
                ['name' => 'Кладка наружных стен', 'category' => 'masonry', 'description' => 'Основная кладка наружного контура', 'unit' => 'м3', 'base_quantity' => 14, 'confidence' => 0.78],
                ['name' => 'Кладка внутренних перегородок', 'category' => 'masonry', 'description' => 'Перегородки и внутренние стены', 'unit' => 'м2', 'base_quantity' => 65, 'confidence' => 0.74],
                ['name' => 'Устройство перемычек', 'category' => 'structural', 'description' => 'Монтаж перемычек над проемами', 'unit' => 'шт', 'base_quantity' => 18, 'confidence' => 0.69],
                ['name' => 'Локальное армирование кладки', 'category' => 'reinforcement', 'description' => 'Усиление отдельных зон кладки', 'unit' => 'м2', 'base_quantity' => 35, 'confidence' => 0.67],
            ],
            'slabs' => [
                ['name' => 'Устройство опалубки плит', 'category' => 'formwork', 'description' => 'Формирование нижней поверхности перекрытия', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.73],
                ['name' => 'Армирование плит перекрытия', 'category' => 'reinforcement', 'description' => 'Монтаж сеток и каркасов', 'unit' => 'т', 'base_quantity' => 1.1, 'confidence' => 0.75],
                ['name' => 'Бетонирование плит перекрытия', 'category' => 'concrete', 'description' => 'Устройство монолитного перекрытия', 'unit' => 'м3', 'base_quantity' => 18, 'confidence' => 0.77],
            ],
            'roof' => [
                ['name' => 'Монтаж стропильной системы', 'category' => 'frame', 'description' => 'Несущая конструкция кровли', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.74],
                ['name' => 'Устройство пароизоляции', 'category' => 'insulation', 'description' => 'Пароизоляционный слой кровельного пирога', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.71],
                ['name' => 'Устройство утепления кровли', 'category' => 'insulation', 'description' => 'Теплоизоляционный слой', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.72],
                ['name' => 'Устройство гидроизоляции кровли', 'category' => 'waterproofing', 'description' => 'Гидроизоляционная мембрана', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.71],
                ['name' => 'Монтаж кровельного покрытия', 'category' => 'covering', 'description' => 'Финишный кровельный слой', 'unit' => 'м2', 'base_quantity' => 90, 'confidence' => 0.76],
            ],
            'engineering' => [
                ['name' => 'Подготовка основания и закладных', 'category' => 'preparation', 'description' => 'Работы под инженерное оборудование', 'unit' => 'компл', 'base_quantity' => 1, 'confidence' => 0.68],
                ['name' => 'Монтаж технологических опор', 'category' => 'structural', 'description' => 'Локальные основания и крепления', 'unit' => 'шт', 'base_quantity' => 4, 'confidence' => 0.66],
                ['name' => 'Антикоррозионная и защитная обработка', 'category' => 'protection', 'description' => 'Защитные покрытия металлоконструкций', 'unit' => 'м2', 'base_quantity' => 20, 'confidence' => 0.63],
            ],
            'custom' => [
                ['name' => 'Подготовительные строительные работы', 'category' => 'preparation', 'description' => 'Организация и подготовка фронта работ', 'unit' => 'компл', 'base_quantity' => 1, 'confidence' => 0.55],
                ['name' => 'Основные строительные работы', 'category' => 'construction', 'description' => 'Исполнение основного объема блока', 'unit' => 'м2', 'base_quantity' => 50, 'confidence' => 0.58],
            ],
        ];

        return $templates[$scopeType] ?? $templates['custom'];
    }
}
