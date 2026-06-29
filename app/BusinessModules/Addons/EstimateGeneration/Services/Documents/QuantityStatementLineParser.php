<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

final class QuantityStatementLineParser
{
    /**
     * @return array{name: string, unit: string, quantity: float, quantity_key: string|null, scope_type: string, source: string, mapped: bool, review_required: bool}|null
     */
    public function parse(string $line, string $source = 'specification'): ?array
    {
        $row = $this->parseQuantityRow($line);

        if ($row === null) {
            return null;
        }

        $quantityKey = $this->quantityKey($row['name']);

        return [
            'name' => $row['name'],
            'unit' => $row['unit'],
            'quantity' => $row['quantity'],
            'quantity_key' => $quantityKey,
            'scope_type' => $quantityKey !== null ? $this->scopeTypeForQuantityKey($quantityKey) : 'unknown',
            'source' => $this->normalizeSource($source, $row['name']),
            'mapped' => $quantityKey !== null,
            'review_required' => $quantityKey === null,
        ];
    }

    /**
     * @return array{name: string, unit: string, quantity: float}|null
     */
    private function parseQuantityRow(string $line): ?array
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $line));
        $normalized = mb_strtolower($line);

        if (
            $line === ''
            || preg_match('/(?:^|\s)(поз\.?|наименование|количество|ед\.?\s*изм|единица\s+измерения|объем\s+работ|объ[её]м\s+работ)(?:\s|$)/u', $normalized) === 1
        ) {
            return null;
        }

        $unitPattern = '(?<unit>шт|штук|компл|м2|м²|м3|м³|п\.?\s*м|пог\.?\s*м|м|т|кг)';
        $quantityPattern = '(?<quantity>\d{1,7}(?:[,.]\d{1,4})?)';
        $match = [];

        if (preg_match('/^(?:\d+[.)]?\s+)?(?<name>.+?)\s+' . $unitPattern . '\s+' . $quantityPattern . '\b/iu', $line, $match) !== 1) {
            if (preg_match('/^(?:\d+[.)]?\s+)?(?<name>.+?)\s+' . $quantityPattern . '\s+' . $unitPattern . '\b/iu', $line, $match) !== 1) {
                return null;
            }
        }

        $name = $this->normalizeName((string) $match['name']);
        $unit = $this->normalizeUnit((string) $match['unit']);
        $quantity = $this->number((string) $match['quantity']);

        if ($name === '' || $quantity <= 0) {
            return null;
        }

        return [
            'name' => $name,
            'unit' => $unit,
            'quantity' => round($quantity, 4),
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $name = trim((string) preg_replace('/^\d+[.)]?\s*/u', '', $name));

        return mb_substr($name, 0, 180);
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($unit)));

        return match ($unit) {
            'штук' => 'шт',
            'м²' => 'м2',
            'м³' => 'м3',
            'п м', 'п. м', 'п.м', 'пог м', 'пог. м', 'пог.м' => 'м',
            default => $unit,
        };
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }

    private function quantityKey(string $name): ?string
    {
        $normalized = mb_strtolower($name);

        return match (true) {
            $this->containsAny($normalized, ['армир', 'арматур']) && $this->containsAny($normalized, ['фундамент', 'ростверк', 'плита фунда', 'ленточн']) => 'foundation.rebar',
            $this->containsAny($normalized, ['гидроизоляц', 'изоляц']) && $this->containsAny($normalized, ['фундамент', 'ростверк', 'плита фунда', 'ленточн']) => 'foundation.waterproofing',
            $this->containsAny($normalized, ['опалуб']) && $this->containsAny($normalized, ['фундамент', 'ростверк', 'плита фунда', 'ленточн']) => 'foundation.formwork',
            $this->containsAny($normalized, ['подготовк']) && $this->containsAny($normalized, ['фундамент', 'ростверк', 'плита фунда', 'ленточн']) => 'foundation.prep',
            $this->containsAny($normalized, ['бетонирован', 'бетон']) && $this->containsAny($normalized, ['фундамент', 'ростверк', 'плита фунда', 'ленточн']) => 'foundation.concrete',
            $this->containsAny($normalized, ['кладк', 'возвед', 'устройств стен', 'монтаж стен']) && $this->containsAny($normalized, ['наруж', 'внешн', 'несущ']) => 'walls.external_volume',
            $this->containsAny($normalized, ['утепл', 'покрыт', 'монтаж', 'устройств']) && $this->containsAny($normalized, ['кровл', 'крыши']) => 'roof.area',
            $this->containsAny($normalized, ['армир', 'арматур']) && $this->containsAny($normalized, ['плита пола', 'пол', 'промышленн']) => 'warehouse.floor_rebar',
            $this->containsAny($normalized, ['бетонирован', 'бетон']) && $this->containsAny($normalized, ['плита пола', 'пол', 'промышленн']) => 'warehouse.floor_concrete',
            $this->containsAny($normalized, ['топпинг', 'упрочнен']) && $this->containsAny($normalized, ['пол', 'покрыт']) => 'warehouse.floor_hardener',
            $this->containsAny($normalized, ['обратн']) && $this->containsAny($normalized, ['засып']) => 'earth.backfill',
            $this->containsAny($normalized, ['разработк']) && $this->containsAny($normalized, ['грунт', 'котлован', 'транше']) => 'earth.trench',
            $this->containsAny($normalized, ['вывоз', 'погруз']) && str_contains($normalized, 'грунт') => 'earth.export',
            $this->containsAny($normalized, ['планировк']) && $this->containsAny($normalized, ['основан', 'площадк', 'грунт']) => 'earth.plan',
            $this->containsAny($normalized, ['стяжк', 'подготовк пола', 'основани пола', 'чернов']) => 'rough.floor',
            $this->containsAny($normalized, ['штукатур', 'шпатлев', 'шпаклев']) => 'rough.walls',
            $this->containsAny($normalized, ['окраск', 'покраск']) => 'finish.paint',
            $this->containsAny($normalized, ['покрытие пола', 'ламинат', 'линолеум', 'чистовое покрытие']) => 'finish.floor',
            $this->containsAny($normalized, ['плитк', 'облицов']) && $this->containsAny($normalized, ['сануз', 'ванн', 'душ', 'мокр']) => 'sanitary.tile',
            $this->containsAny($normalized, ['потолок', 'потолк']) => 'office.ceiling',
            $this->containsAny($normalized, ['светиль']) => 'warehouse.lighting',
            $this->containsAny($normalized, ['радиатор', 'конвектор']) => 'heating.radiators',
            $this->containsAny($normalized, ['тепловой узел', 'теплового узла', 'теплопункт']) => 'heating.unit',
            $this->containsAny($normalized, ['кабель']) => 'electrical.power_lines',
            $this->containsAny($normalized, ['лоток']) => 'electrical.trays',
            $this->containsAny($normalized, ['канализац']) && $this->containsAny($normalized, ['труб', 'трубопровод']) => 'sewerage.pipe',
            $this->containsAny($normalized, ['отоп']) && $this->containsAny($normalized, ['труб', 'трубопровод']) => 'heating.pipe',
            $this->containsAny($normalized, ['водоснаб', 'хвс', 'гвс']) && $this->containsAny($normalized, ['труб', 'трубопровод']) => 'plumbing.pipe',
            $this->containsAny($normalized, ['сантех', 'унитаз', 'раков', 'душ']) => 'sanitary.points',
            $this->containsAny($normalized, ['воздуховод', 'вентиляц']) => 'ventilation.air_exchange',
            $this->containsAny($normalized, ['решетк', 'диффузор']) => 'ventilation.office_points',
            $this->containsAny($normalized, ['ворот']) => 'openings.gates',
            $this->containsAny($normalized, ['окн']) => 'openings.windows',
            $this->containsAny($normalized, ['двер']) => 'openings.doors',
            default => null,
        };
    }

    private function scopeTypeForQuantityKey(string $quantityKey): string
    {
        return match (true) {
            str_starts_with($quantityKey, 'earth.') => 'earthworks',
            str_starts_with($quantityKey, 'foundation.') => 'foundation',
            str_starts_with($quantityKey, 'walls.') => 'walls',
            str_starts_with($quantityKey, 'roof.') => 'roof',
            in_array($quantityKey, ['warehouse.floor_concrete', 'warehouse.floor_rebar'], true) => 'slabs',
            $quantityKey === 'warehouse.floor_hardener' => 'industrial_floor',
            str_starts_with($quantityKey, 'electrical.'), $quantityKey === 'warehouse.lighting' => 'electrical',
            str_starts_with($quantityKey, 'heating.') => 'heating',
            str_starts_with($quantityKey, 'plumbing.'), str_starts_with($quantityKey, 'sewerage.'), str_starts_with($quantityKey, 'sanitary.') => 'plumbing',
            str_starts_with($quantityKey, 'openings.'), $quantityKey === 'warehouse.gates' => 'openings',
            str_starts_with($quantityKey, 'ventilation.') => 'ventilation',
            str_starts_with($quantityKey, 'rough.'), str_starts_with($quantityKey, 'finish.'), str_starts_with($quantityKey, 'office.') => 'finishing',
            default => 'engineering',
        };
    }

    private function normalizeSource(string $source, string $name): string
    {
        if ($source === 'work_volume_statement') {
            return $source;
        }

        $normalized = mb_strtolower($name);

        if ($this->containsAny($normalized, ['разработк грунт', 'обратн', 'стяжк', 'штукатур', 'окраск', 'покраск'])) {
            return 'work_volume_statement';
        }

        return 'specification';
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
