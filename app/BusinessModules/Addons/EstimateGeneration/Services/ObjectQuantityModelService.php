<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

class ObjectQuantityModelService
{
    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public function build(array $analysis): array
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $description = (string) ($object['description'] ?? '');
        $normalizedDescription = mb_strtolower($description);
        $area = max((float) ($object['area'] ?? 100), 30.0);
        $floors = max((int) ($object['floors'] ?? $this->detectFloors($description)), 1);
        $rooms = max((int) ($object['rooms'] ?? $this->detectRooms($description)), 1);
        $warehouseArea = $this->detectZoneArea($normalizedDescription, ['склад']);
        $officeArea = $this->detectZoneArea($normalizedDescription, ['офис']);
        $isWarehouseLike = str_contains($normalizedDescription, 'склад') || str_contains(mb_strtolower((string) ($object['building_type'] ?? '')), 'склад');
        $isMixedUse = $warehouseArea !== null && ($officeArea !== null || str_contains($normalizedDescription, 'офис'));

        if (
            $isMixedUse
            && $warehouseArea !== null
            && $officeArea !== null
            && $warehouseArea >= $area * 0.95
            && $officeArea >= $area * 0.95
        ) {
            $warehouseArea = $area * 0.6;
            $officeArea = $area * 0.3;
        }

        if ($warehouseArea === null && $isWarehouseLike) {
            $warehouseArea = $officeArea !== null ? max($area - $officeArea, 0.0) : $area;
        }

        if ($officeArea === null && $isMixedUse) {
            $remainingArea = max($area - (float) $warehouseArea, 0.0);

            if ($remainingArea <= 0 && (float) $warehouseArea >= $area * 0.95) {
                $officeArea = $area * 0.3;
                $warehouseArea = $area * 0.6;
            } else {
                $officeArea = $remainingArea;
            }
        }

        $warehouseArea = min(max((float) ($warehouseArea ?? 0), 0.0), $area);
        $officeArea = min(max((float) ($officeArea ?? 0), 0.0), $area);
        $effectiveWarehouseArea = $warehouseArea > 0 ? $warehouseArea : $area;
        $effectiveOfficeArea = $officeArea > 0 ? $officeArea : max($area * 0.3, 30);
        $commonArea = max($area - $warehouseArea - $officeArea, 0.0);
        $roofType = str_contains($normalizedDescription, 'плоск') ? 'flat' : 'pitched';
        $floorArea = $area / $floors;
        $dimensions = is_array($object['dimensions'] ?? null) ? $object['dimensions'] : [];
        $length = isset($dimensions['length']) ? (float) $dimensions['length'] : sqrt($floorArea * 1.35);
        $width = isset($dimensions['width']) ? (float) $dimensions['width'] : $floorArea / max($length, 1);
        $perimeter = 2 * ($length + $width);
        $wallHeight = 3.0;
        $externalWallArea = $perimeter * $wallHeight * $floors;
        $openingsArea = max($area * 0.16, $rooms * 2.4);
        $internalWallArea = $area * 1.15;
        $roofArea = $roofType === 'flat' ? $floorArea * 1.05 : $floorArea * 1.28;
        $foundationLength = $perimeter + ($length * 0.35) + ($width * 0.25);
        $foundationConcreteVolume = $foundationLength * 0.4 * 0.6;
        $foundationEarthVolume = $foundationLength * 0.65 * 0.9;
        $foundationPrepVolume = $foundationLength * 0.65 * 0.2;
        $slabArea = $floorArea * max($floors - 1, 1);
        $wetRooms = max((int) ceil($rooms / 4), 1);
        $mainCableLength = ($effectiveWarehouseArea * 1.15) + ($effectiveOfficeArea * 1.6) + ($perimeter * 1.4);
        $trayLength = ($effectiveWarehouseArea * 0.72) + ($effectiveOfficeArea * 0.95) + ($perimeter * 0.8);
        $powerLineLength = ($effectiveWarehouseArea * 1.35) + ($effectiveOfficeArea * 0.65) + ($perimeter * 1.1);
        $lightingLineLength = ($effectiveWarehouseArea * 1.05) + ($effectiveOfficeArea * 1.25) + ($perimeter * 0.55);
        $groundingLength = $perimeter + max($effectiveWarehouseArea / 60, 12);

        $quantities = [
            'site.setup' => $this->quantity(1, 'компл', 'Один комплект подготовительных работ на объект'),
            'site.fence' => $this->quantity(max($perimeter + 20, 40), 'м', 'Периметр пятна застройки с запасом для временного ограждения'),
            'site.power' => $this->quantity(1, 'компл', 'Временное электроснабжение строительной площадки'),
            'site.water' => $this->quantity(1, 'компл', 'Временное водоснабжение строительной площадки'),
            'site.cleaning' => $this->quantity($floorArea, 'м2', 'Площадь пятна работ по первому этажу'),
            'site.geodesy' => $this->quantity(1, 'компл', 'Разбивка осей и контроль отметок объекта'),
            'earth.trench' => $this->quantity($foundationEarthVolume, 'м3', 'Длина ленты фундамента × ширина траншеи × глубина'),
            'earth.backfill' => $this->quantity($foundationEarthVolume * 0.45, 'м3', 'Ориентировочный объем обратной засыпки после устройства ленты'),
            'earth.export' => $this->quantity($foundationEarthVolume * 0.55, 'м3', 'Излишек грунта после обратной засыпки'),
            'earth.plan' => $this->quantity($floorArea, 'м2', 'Планировка основания по пятну застройки'),
            'foundation.prep' => $this->quantity($foundationPrepVolume, 'м3', 'Песчано-щебеночная подготовка под ленточный фундамент'),
            'foundation.formwork' => $this->quantity($foundationLength * 1.2, 'м2', 'Длина ленты × две боковые поверхности'),
            'foundation.rebar' => $this->quantity($foundationConcreteVolume * 0.085, 'т', 'Ориентировочно 85 кг арматуры на 1 м3 бетона ленты'),
            'foundation.concrete' => $this->quantity($foundationConcreteVolume, 'м3', 'Длина ленты × ширина 0,4 м × высота 0,6 м'),
            'foundation.waterproofing' => $this->quantity($foundationLength * 1.1, 'м2', 'Вертикальная и горизонтальная гидроизоляция ленты'),
            'walls.external' => $this->quantity(max($externalWallArea - $openingsArea, 1), 'м2', 'Площадь наружных стен минус ориентировочная площадь проемов'),
            'walls.external_volume' => $this->quantity(max($externalWallArea - $openingsArea, 1) * 0.4, 'м3', 'Площадь наружных стен × толщина 400 мм'),
            'walls.internal' => $this->quantity($internalWallArea, 'м2', 'Ориентировочная площадь внутренних перегородок по площади и комнатности'),
            'walls.lintels' => $this->quantity($rooms + 4, 'шт', 'Перемычки по основным дверным и оконным проемам'),
            'slabs.area' => $this->quantity($slabArea, 'м2', 'Площадь перекрытия между этажами или пола по грунту'),
            'slabs.rebar' => $this->quantity($slabArea * 0.012, 'т', 'Ориентировочный расход арматуры для перекрытий'),
            'stairs.flight' => $this->quantity(max($floors - 1, 1), 'компл', 'Один комплект лестницы на каждый переход между этажами'),
            'stairs.railing' => $this->quantity(max($floors - 1, 1) * 8, 'м', 'Ориентировочная длина ограждений лестницы'),
            'roof.area' => $this->quantity($roofArea, 'м2', 'Площадь этажа × коэффициент двухскатной кровли'),
            'roof.flat_area' => $this->quantity($roofArea, 'м2', 'Площадь этажа × коэффициент плоской кровли'),
            'roof.gutter' => $this->quantity($perimeter, 'м', 'Водосточная система по периметру кровли'),
            'openings.windows' => $this->quantity($openingsArea * 0.72, 'м2', 'Ориентировочная площадь оконных блоков'),
            'openings.doors' => $this->quantity(max($rooms + 3, 4), 'шт', 'Внутренние и входные двери по комнатности'),
            'facade.area' => $this->quantity(max($externalWallArea - $openingsArea, 1), 'м2', 'Площадь фасада по наружным стенам без проемов'),
            'electrical.points' => $this->quantity(max($rooms * 7 + $area * 0.12, 20), 'точка', 'Розетки, выключатели и световые точки по комнатам и площади'),
            'electrical.cable' => $this->quantity($area * 5.5, 'м', 'Ориентировочная длина кабельных линий по площади дома'),
            'electrical.main_cable' => $this->quantity($mainCableLength, 'м', 'Магистральные кабельные линии с учетом складской, офисной зоны и периметра'),
            'electrical.trays' => $this->quantity($trayLength, 'м', 'Кабельные лотки и трассы по складской и офисной зонам'),
            'electrical.power_lines' => $this->quantity($powerLineLength, 'м', 'Силовые линии к оборудованию, воротам и распределительным щитам'),
            'lighting.lines' => $this->quantity($lightingLineLength, 'м', 'Групповые линии освещения отдельно от силовых кабелей'),
            'electrical.grounding' => $this->quantity($groundingLength, 'м', 'Контур заземления по периметру и перемычки к металлоконструкциям'),
            'plumbing.points' => $this->quantity($wetRooms * 5 + 3, 'точка', 'Сантехнические точки по мокрым зонам'),
            'plumbing.pipe' => $this->quantity($area * 0.55, 'м', 'Внутридомовая разводка водоснабжения'),
            'sewerage.points' => $this->quantity($wetRooms * 4 + 2, 'точка', 'Точки канализации по санузлам и кухне'),
            'sewerage.pipe' => $this->quantity($area * 0.35, 'м', 'Внутридомовая разводка канализации'),
            'heating.radiators' => $this->quantity(max((int) ceil($area / 18), $rooms), 'шт', 'Радиаторы по площади и количеству комнат'),
            'heating.pipe' => $this->quantity($area * 1.2, 'м', 'Разводка отопления по площади дома'),
            'ventilation.points' => $this->quantity($wetRooms + 3, 'шт', 'Вытяжные и приточные точки по мокрым зонам и жилым помещениям'),
            'rough.floor' => $this->quantity($area, 'м2', 'Черновая подготовка пола по общей площади'),
            'rough.walls' => $this->quantity($internalWallArea + $externalWallArea, 'м2', 'Черновая подготовка внутренних поверхностей стен'),
            'finish.floor' => $this->quantity($area * 0.72, 'м2', 'Чистовое покрытие сухих помещений'),
            'finish.tile' => $this->quantity($area * 0.12, 'м2', 'Плитка во влажных зонах'),
            'finish.paint' => $this->quantity(($internalWallArea + $externalWallArea) * 0.75, 'м2', 'Финишная отделка стен по расчетной площади поверхностей'),
            'networks.external' => $this->quantity(1, 'компл', 'Наружные сети как отдельный проверяемый комплект'),
            'siteworks.area' => $this->quantity(max($floorArea * 1.5, 80), 'м2', 'Минимальное благоустройство вокруг пятна застройки'),
            'warehouse.floor' => $this->quantity($effectiveWarehouseArea, 'м2', 'Промышленный пол по складской зоне'),
            'warehouse.floor_concrete' => $this->quantity($effectiveWarehouseArea * 0.18, 'м3', 'Промышленная плита пола толщиной 180 мм'),
            'warehouse.floor_rebar' => $this->quantity($effectiveWarehouseArea * 0.014, 'т', 'Ориентировочная арматура промышленной плиты пола'),
            'warehouse.floor_hardener' => $this->quantity($effectiveWarehouseArea, 'м2', 'Упрочненный верхний слой промышленного пола'),
            'warehouse.floor_joints' => $this->quantity(max($effectiveWarehouseArea / 18, 20), 'м', 'Нарезка и герметизация деформационных швов пола'),
            'warehouse.frame_weight' => $this->quantity($effectiveWarehouseArea * 0.055, 'т', 'Ориентировочная масса металлокаркаса по площади склада'),
            'warehouse.columns' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 55), 8), 'шт', 'Колонны металлокаркаса по складской сетке'),
            'warehouse.beams' => $this->quantity($effectiveWarehouseArea * 0.035, 'т', 'Балки, фермы и связи металлокаркаса'),
            'warehouse.envelope' => $this->quantity(max($externalWallArea - $openingsArea, 1), 'м2', 'Стеновые ограждающие конструкции без повторного учета кровли'),
            'warehouse.wall_panels' => $this->quantity(max($externalWallArea - $openingsArea, 1), 'м2', 'Стеновые сэндвич-панели по наружному контуру'),
            'warehouse.panel_flashings' => $this->quantity($perimeter * 1.6, 'м', 'Доборные элементы и нащельники сэндвич-панелей'),
            'warehouse.gates' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 600), 2), 'шт', 'Ворота и погрузочные узлы по площади склада'),
            'warehouse.loading_nodes' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 700), 1), 'компл', 'Погрузочные узлы и обрамления ворот'),
            'warehouse.lighting' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 45), 20), 'шт', 'Светильники промышленного освещения по площади'),
            'warehouse.fire' => $this->quantity($area, 'м2', 'Пожарная сигнализация и оповещение по площади склада'),
            'warehouse.low_current' => $this->quantity($area * 0.35, 'м', 'Слаботочные трассы по площади склада'),
            'warehouse.roads' => $this->quantity(max($effectiveWarehouseArea * 0.55, 300), 'м2', 'Площадки и подъездные зоны вокруг склада'),
            'office.floor' => $this->quantity($effectiveOfficeArea, 'м2', 'Площадь офисной зоны'),
            'office.partitions' => $this->quantity($effectiveOfficeArea * 0.85, 'м2', 'Офисные перегородки по площади кабинетов'),
            'office.ceiling' => $this->quantity($effectiveOfficeArea, 'м2', 'Подвесной потолок офисной зоны'),
            'office.paint' => $this->quantity($effectiveOfficeArea * 2.4, 'м2', 'Окраска стен офисной зоны'),
            'office.floor_finish' => $this->quantity($effectiveOfficeArea * 0.78, 'м2', 'Чистовое покрытие пола офисной зоны'),
            'office.doors' => $this->quantity(max((int) ceil($effectiveOfficeArea / 42), 4), 'шт', 'Дверные блоки офисных помещений'),
            'office.electrical_points' => $this->quantity(max((int) ceil($effectiveOfficeArea / 8), 20), 'точка', 'Розетки, выключатели и рабочие места офиса'),
            'office.network_points' => $this->quantity(max((int) ceil($effectiveOfficeArea / 12), 12), 'точка', 'СКС и слаботочные точки офисной зоны'),
            'sanitary.rooms' => $this->quantity(max((int) ceil($effectiveOfficeArea / 180), 2), 'помещение', 'Санузлы офисно-складского корпуса'),
            'sanitary.points' => $this->quantity(max((int) ceil($effectiveOfficeArea / 45), 8), 'точка', 'Сантехнические приборы и точки подключения'),
            'sanitary.tile' => $this->quantity(max($effectiveOfficeArea * 0.16, 35), 'м2', 'Плиточная отделка санузлов'),
            'heating.unit' => $this->quantity(1, 'компл', 'Тепловой узел или котельная объекта'),
            'heating.air_curtains' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 500), 1), 'шт', 'Воздушно-тепловые завесы ворот и входов'),
            'ventilation.air_exchange' => $this->quantity($area, 'м2', 'Приточно-вытяжная вентиляция по площади корпуса'),
            'ventilation.office_points' => $this->quantity(max((int) ceil($effectiveOfficeArea / 45), 6), 'точка', 'Воздухораспределители офисной зоны'),
            'ventilation.warehouse_points' => $this->quantity(max((int) ceil($effectiveWarehouseArea / 120), 4), 'точка', 'Вентиляционные точки складской зоны'),
            'server.room' => $this->quantity(str_contains($normalizedDescription, 'сервер') ? 1 : 0.01, 'компл', 'Серверная или телекоммуникационный шкаф'),
            'entrance.group' => $this->quantity(str_contains($normalizedDescription, 'вход') ? 1 : 0.01, 'компл', 'Входная группа здания'),
        ];

        return [
            'area' => round($area, 2),
            'floors' => $floors,
            'rooms' => $rooms,
            'floor_area' => round($floorArea, 2),
            'length' => round($length, 2),
            'width' => round($width, 2),
            'perimeter' => round($perimeter, 2),
            'zones' => [
                'warehouse_area' => round($warehouseArea, 2),
                'office_area' => round($officeArea, 2),
                'common_area' => round($commonArea, 2),
            ],
            'features' => [
                'roof_type' => $roofType,
                'mixed_use' => $isMixedUse,
            ],
            'assumptions' => [
                'Если точные размеры не указаны, габариты рассчитаны из площади этажа с прямоугольной формой здания.',
                'Высота этажа принята 3,0 м для предварительной сметы.',
                'Площади инженерных систем рассчитаны укрупненно и требуют проверки по проекту.',
            ],
            'quantities' => $quantities,
        ];
    }

    /**
     * @return array{value: float, unit: string, basis: string}
     */
    private function quantity(float $value, string $unit, string $basis): array
    {
        return [
            'value' => round(max($value, 0.01), 2),
            'unit' => $unit,
            'basis' => $basis,
        ];
    }

    private function detectFloors(string $description): int
    {
        if (preg_match('/(\d+)\s*(?:этаж|этажа|этажей)/ui', $description, $matches) === 1) {
            return max((int) $matches[1], 1);
        }

        $normalized = mb_strtolower($description);

        if (
            str_contains($normalized, 'двухэтаж')
            || str_contains($normalized, '2-этаж')
            || str_contains($normalized, 'втором этаже')
        ) {
            return 2;
        }

        if (
            str_contains($normalized, 'трехэтаж')
            || str_contains($normalized, 'трёхэтаж')
            || str_contains($normalized, '3-этаж')
            || str_contains($normalized, 'третьем этаже')
        ) {
            return 3;
        }

        return str_contains($normalized, 'мансард') ? 2 : 1;
    }

    private function detectRooms(string $description): int
    {
        if (preg_match('/(\d+)\s*(?:комнат|комнаты|комната)/ui', $description, $matches) === 1) {
            return max((int) $matches[1], 1);
        }

        return 4;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function detectZoneArea(string $description, array $keywords): ?float
    {
        $detected = [];

        foreach ($keywords as $keyword) {
            $pattern = '/' . preg_quote($keyword, '/') . '[^0-9]{0,80}(\d+(?:[,.]\d+)?)\s*(?:м2|м²|кв\.?\s*м|кв\s*м)/ui';

            if (preg_match_all($pattern, $description, $matches) > 0) {
                foreach ($matches[1] as $match) {
                    $detected[] = (float) str_replace(',', '.', $match);
                }
            }
        }

        if ($detected === []) {
            return null;
        }

        return $detected[array_key_last($detected)];
    }
}
