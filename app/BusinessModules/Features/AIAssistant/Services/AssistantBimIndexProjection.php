<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantBimIndexProjection
{
    private const PROPERTY_NAMES = '/^(?:PredefinedType|ObjectType|ElementType|TypeName|ModelReference|Manufacturer|Material|SystemName|SystemType|Reference|IsExternal|LoadBearing|FireRating|AcousticRating|ThermalTransmittance|CoolingCapacity|HeatingCapacity|NominalCoolingCapacity|NominalHeatingCapacity|AirFlowRate|NominalAirFlowRate|Power|NominalPower|Voltage|Frequency|Efficiency|Width|Height|Length|Thickness|Diameter|Area|Volume|Weight|Count|NetArea|GrossArea|NetVolume|GrossVolume|NetWeight|GrossWeight|NetSideArea|GrossSideArea|CrossSectionArea|OuterSurfaceArea|NetSurfaceArea|GrossSurfaceArea|Perimeter|Depth|Тип|ТипОбъекта|ТипСистемы|НаименованиеСистемы|GP_Наименование|ADSK_Наименование|Наименование|Категория|Марка|Модель|Производитель|Материал|Мощность|Холодопроизводительность|Теплопроизводительность|РасходВоздуха|Ширина|Высота|Длина|Толщина|Диаметр|Площадь|Объем|Объём|Масса|Количество)$/iu';

    public function element(array $row): array
    {
        $result = array_intersect_key($row, array_flip(['id', 'project_id', 'version_id', 'derivative_id', 'express_id', 'global_id', 'category', 'name', 'updated_at']));
        $raw = is_array($row['properties'] ?? null) ? $row['properties'] : [];
        $attributes = is_array($raw['_ifc'] ?? null) ? $raw['_ifc'] : [];
        $result['index_schema_version'] = ($attributes['schema_version'] ?? null) === 2 ? 2 : null;
        $result['semantic_coverage'] = $result['index_schema_version'] === 2 ? 'schema2' : 'unproven';
        $result['attributes'] = array_filter([
            'predefined_type' => $this->scalar($attributes['predefined_type'] ?? null),
            'object_type' => $this->scalar($attributes['object_type'] ?? null),
            'description' => $this->scalar($attributes['description'] ?? null),
            'type_name' => $this->scalar($attributes['type_name'] ?? null),
            'type_predefined_type' => $this->scalar($attributes['type_predefined_type'] ?? null),
            'type_object_type' => $this->scalar($attributes['type_object_type'] ?? null),
            'storey' => $this->scalar(is_array($attributes['storey'] ?? null) ? $attributes['storey']['name'] ?? null : $attributes['storey'] ?? null),
        ], static fn ($value): bool => $value !== null);
        $result['equipment_kind'] = $this->equipment($result, $raw, $row['classifications'] ?? []);
        $result['properties'] = $this->engineering($raw);
        $result['quantities'] = $this->engineering(is_array($raw['quantities'] ?? null) ? $raw['quantities'] : []);
        $result['materials'] = $this->labels($raw['materials'] ?? []);
        $result['classifications'] = $this->labels($row['classifications'] ?? []);
        $result['quantity_units'] = [];
        foreach (is_array($raw['quantity_units'] ?? null) ? array_slice($raw['quantity_units'], 0, 100, true) : [] as $path => $descriptor) {
            if (is_string($path) && is_array($descriptor) && isset($result['quantities'][$path])) {
                $result['quantity_units'][$path] = array_filter(array_intersect_key($descriptor,
                    array_flip(['type', 'unit_type', 'name', 'prefix'])), fn ($value): bool => $this->scalar($value) !== null);
            }
        }
        $result['property_coverage'] = 'engineering_allowlist';

        return $result;
    }

    public function matches(array $row, array $filters): bool
    {
        foreach (['material_query' => 'materials', 'classification_query' => 'classifications', 'storey_query' => 'attributes'] as $filter => $field) {
            if (! empty($filters[$filter]) && ! $this->contains($field === 'attributes' ? [$row[$field]['storey'] ?? ''] : $row[$field] ?? [], $filters[$filter])) {
                return false;
            }
        }
        if (! empty($filters['equipment_kind']) && ($row['equipment_kind'] ?? 'unknown') !== $filters['equipment_kind']) {
            return false;
        }
        if (! empty($filters['property_name'])) {
            $matched = false;
            foreach ($row['properties'] ?? [] as $path => $value) {
                if ($this->contains([$path], $filters['property_name']) && (($filters['property_value'] ?? null) === null || $filters['property_value'] === '' || $this->contains([$value], $filters['property_value']))) {
                    $matched = true;
                }
            }
            if (! $matched) {
                return false;
            }
        }
        $term = trim($filters['element_query'] ?? '');
        if ($term !== '' && empty($filters['category_match'])) {
            $kind = $this->queryEquipment($term);
            if ($kind !== null) {
                return ($row['equipment_kind'] ?? 'unknown') === $kind;
            }
            return $this->contains([$row['name'] ?? '', $row['global_id'] ?? '', $row['category'] ?? '',
                ...array_values($row['properties'] ?? []), ...array_values($row['attributes'] ?? []),
                ...($row['materials'] ?? []), ...($row['classifications'] ?? [])], $term);
        }

        return true;
    }

    public function queryEquipment(string $query): ?string
    {
        $term = mb_strtolower($query);
        return match (true) {
            preg_match('/кондиционер|сплит[ -]?систем|air[ -]?condition|split[ -]?system/u', $term) === 1 => 'air_conditioner',
            preg_match('/вентилятор|\bfans?\b/u', $term) === 1 => 'fan',
            preg_match('/насос|\bpumps?\b/u', $term) === 1 => 'pump',
            preg_match('/кот[её]л|котлы|котлов|\bboilers?\b/u', $term) === 1 => 'boiler',
            preg_match('/чиллер|\bchillers?\b/u', $term) === 1 => 'chiller',
            default => null,
        };
    }

    public function quantities(array $row, array $units): array
    {
        $result = [];
        foreach ($row['quantities'] ?? [] as $path => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                continue;
            }
            $dimension = match (true) {
                preg_match('/volume|объ[её]м/iu', $path) === 1 => 'VOLUMEUNIT',
                preg_match('/area|площад/iu', $path) === 1 => 'AREAUNIT',
                preg_match('/weight|mass|масса/iu', $path) === 1 => 'MASSUNIT',
                preg_match('/count|количество/iu', $path) === 1 => 'COUNTUNIT',
                preg_match('/length|width|height|thickness|diameter|depth|perimeter|длина|ширина|высота|толщина|диаметр/iu', $path) === 1 => 'LENGTHUNIT',
                default => null,
            };
            $candidates = [];
            $explicit = $row['quantity_units'][$path] ?? null;
            foreach (is_array($explicit) ? [$explicit] : $units as $unit) {
                if (is_array($unit) && ($unit['unit_type'] ?? null) === $dimension && is_string($unit['name'] ?? null)
                    && ($unit['type'] ?? 'IFCSIUNIT') === 'IFCSIUNIT') {
                    $label = ($unit['prefix'] ?? '') !== '' ? $unit['prefix'].' '.$unit['name'] : $unit['name'];
                    $candidates[$label] = true;
                }
            }
            $result[] = ['name' => $path, 'value' => (float) $value, 'dimension' => $dimension,
                'unit' => $dimension === 'COUNTUNIT' ? 'count' : (count($candidates) === 1 ? array_key_first($candidates) : null)];
        }

        return $result;
    }

    public function diff(array $before, array $after, int $limit): array
    {
        $maps = [];
        $complete = true;
        foreach ([$before, $after] as $rows) {
            $map = [];
            foreach ($rows as $row) {
                $id = $row['global_id'] ?? null;
                if (! is_string($id) || $id === '' || isset($map[$id])) {
                    $complete = false;
                    continue;
                }
                $map[$id] = array_intersect_key($row, array_flip(['category', 'name', 'properties', 'quantities', 'quantity_units', 'materials', 'classifications', 'attributes']));
            }
            $maps[] = $map;
        }
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];
        $changes = [];
        foreach ($maps[0] as $id => $row) {
            $kind = ! isset($maps[1][$id]) ? 'removed' : ($row === $maps[1][$id] ? 'unchanged' : 'changed');
            $counts[$kind]++;
            if ($kind !== 'unchanged' && count($changes) < $limit) {
                $changes[] = ['global_id' => $id, 'kind' => $kind, 'before' => $row, 'after' => $maps[1][$id] ?? null];
            }
        }
        foreach (array_diff_key($maps[1], $maps[0]) as $id => $row) {
            $counts['added']++;
            if (count($changes) < $limit) {
                $changes[] = ['global_id' => $id, 'kind' => 'added', 'before' => null, 'after' => $row];
            }
        }

        return ['identity_complete' => $complete, 'counts' => $counts, 'changes' => $changes,
            'has_more' => array_sum($counts) - $counts['unchanged'] > count($changes), 'scope' => 'engineering_projection'];
    }

    private function engineering(array $values, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }
        $result = [];
        foreach ($values as $name => $value) {
            if (! is_string($name) || in_array($name, ['quantities', 'materials', '_ifc', 'quantity_units'], true)) {
                continue;
            }
            $path = $prefix === '' ? $name : $prefix.'.'.$name;
            if (is_array($value)) {
                $result += $this->engineering($value, $path, $depth + 1);
            } elseif (preg_match(self::PROPERTY_NAMES, $name) === 1 && ($safe = $this->scalar($value)) !== null) {
                $result[mb_substr($path, 0, 200)] = $safe;
            }
            if (count($result) >= 100) {
                break;
            }
        }
        ksort($result);
        return array_slice($result, 0, 100, true);
    }

    private function labels(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $result = [];
        foreach (array_slice($values, 0, 30) as $value) {
            if (($safe = $this->scalar($value)) !== null) {
                $result[] = (string) $safe;
            }
        }
        sort($result);
        return array_values(array_unique($result));
    }

    public function materialLabel(string $value): ?string
    {
        $safe = $this->scalar($value);

        return is_string($safe) ? $safe : null;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            if (preg_match('/https?:\/\/|[\w.+-]+@[\w.-]+\.[a-z]{2,}|(?:token|secret|password)\s*[:=]/iu', $value) === 1) {
                return null;
            }
            return mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255);
        }
        return is_int($value) || is_bool($value) || (is_float($value) && is_finite($value)) ? $value : null;
    }

    private function contains(array $values, string $query): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', str_replace('ё', 'е', mb_strtolower(trim($query))), -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
        foreach ($tokens as $token) {
            $needle = mb_strlen($token) > 4 && preg_match('/^[а-я]+$/u', $token) === 1
                ? (preg_replace('/(?:ого|его|ому|ему|ыми|ими|иях|ая|яя|ой|ую|юю|ые|ых|ие|ий|ии|ям|ей|ам|ов|ах|ях|а|я|ы|и|у|е)$/u', '', $token) ?? $token) : $token;
            $found = false;
            foreach ($values as $value) {
                if (is_scalar($value) && str_contains(str_replace('ё', 'е', mb_strtolower((string) $value)), $needle)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }
        return true;
    }

    public static function propertyPattern(): string
    {
        return substr(self::PROPERTY_NAMES, 1, -3);
    }

    public static function genericEquipmentCategories(): array
    {
        return ['IFCUNITARYEQUIPMENT', 'IFCBUILDINGELEMENTPROXY', 'IFCFLOWTERMINAL', 'IFCFLOWMOVINGDEVICE',
            'IFCFLOWENERGYCONVERSIONDEVICE', 'IFCDISTRIBUTIONELEMENT', 'IFCDISTRIBUTIONFLOWELEMENT',
            'IFCFLOWCONTROLLER', 'IFCFLOWTREATMENTDEVICE'];
    }

    private static function equipmentWord(string $kind): string
    {
        return match ($kind) {
            'air_conditioner' => '(?:кондиционер(?:ы|а|ов|у|ам|ом|ами|е|ах)?|сплит[ -]?систем(?:а|ы|у|е|ой|ами|ах)?|air[ -]?condition(?:er|ers|ing[ -]?unit)?|split[ -]?systems?)',
            'fan' => '(?:вентилятор(?:ы|а|ов|у|ам|ом|ами|е|ах)?|fans?)',
            'pump' => '(?:насос(?:ы|а|ов|у|ам|ом|ами|е|ах)?|pumps?)',
            'boiler' => '(?:кот[её]л|котлы|котла|котлов|котлу|boilers?)',
            'chiller' => '(?:чиллер(?:ы|а|ов|у|ам|ом|ами|е|ах)?|chillers?)',
            default => '(?!)',
        };
    }

    public static function equipmentPattern(string $kind): string
    {
        return '(?:^|[^[:alnum:]])'.self::equipmentWord($kind).'(?:[ _-]?[0-9]+)?(?:$|[^[:alnum:]])';
    }

    public static function equipmentReferencePattern(string $kind): string
    {
        return self::equipmentReferencePrefix().self::equipmentWord($kind).'(?:$|[^[:alnum:]])';
    }

    private static function equipmentReferencePrefix(): string
    {
        $separator = '[[:space:][:punct:]«»]';
        $accessories = '(?:(?:кронштейн|трубопровод|подставк|креплен|отверст|опор|рам|панел|корпус|кожух|реш[её]тк|воздуховод|кабел|канал|шахт|патрубок|патрубк|заглушк|муфт|фланец|фланц|изоляци)[[:alpha:]]*|brackets?|pipes?|stands?|mounts?|mountings?|openings?|supports?|frames?|panels?|housings?|covers?|grilles?|ducts?|cables?)';
        return '(?:^|[^[:alnum:]])(?:(?:для|к|под|for|to|under)'.$separator.'*|'.$accessories.$separator.'+(?:[[:alpha:]][[:alnum:]_-]*'.$separator.'+){0,3})';
    }

    private static function airConditionerUnitWord(): string
    {
        return '(?:(?:наружн[[:alpha:]]*|внутренн[[:alpha:]]*)[[:space:]_-]+блок[[:alpha:]]*|(?:outdoor|indoor)[[:space:]_-]+units?)[[:space:][:punct:]«»]+(?:[[:alpha:]][[:alnum:]_-]*[[:space:]]+){0,2}(?:мульти[ -]?зональн[[:alpha:]]*|multi[ -]?zone[[:alpha:]]*|vrf|vrv|сплит[ -]?систем[[:alpha:]]*|split[ -]?systems?|кондиционер[[:alpha:]]*|air[ -]?condition[[:alpha:]]*)';
    }

    public static function airConditionerUnitPattern(): string
    {
        return '(?:^|[^[:alnum:]])'.self::airConditionerUnitWord().'(?:$|[^[:alnum:]])';
    }

    public static function airConditionerUnitReferencePattern(): string
    {
        return self::equipmentReferencePrefix().self::airConditionerUnitWord().'(?:$|[^[:alnum:]])';
    }

    public static function roomCategoryPattern(): string
    {
        return '^(?:помещени[ея]|rooms?|spaces?)$';
    }

    private function classifierIdentity(array $values): \Generator
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                yield from $this->classifierIdentity($value);
            } elseif (is_string($key) && preg_match('/^(?:PredefinedType|ObjectType|ElementType|TypeName|ModelReference|Reference|Тип|ТипОбъекта|GP_Наименование|ADSK_Наименование|Наименование|Марка|Модель)$/iu', $key) === 1
                && ($safe = $this->scalar($value)) !== null) {
                yield ['key' => $key, 'value' => $safe];
            }
        }
    }

    private function equipment(array $row, array $raw, mixed $rawClassifications): string
    {
        $category = strtoupper($row['category'] ?? '');
        $direct = ['IFCFAN' => 'fan', 'IFCPUMP' => 'pump', 'IFCBOILER' => 'boiler', 'IFCCHILLER' => 'chiller'];
        if (isset($direct[$category])) {
            return $direct[$category];
        }
        if (! in_array($category, self::genericEquipmentCategories(), true)) {
            return 'unknown';
        }
        $room = is_array($raw['Прочее'] ?? null) ? $raw['Прочее']['Категория'] ?? null : null;
        if (is_string($room) && preg_match('/'.self::roomCategoryPattern().'/iu', trim($room, ' ')) === 1) {
            return 'unknown';
        }
        $identity = [];
        $enumValues = [$row['attributes']['predefined_type'] ?? '', $row['attributes']['type_predefined_type'] ?? ''];
        foreach ($this->classifierIdentity($raw) as $field) {
            $identity[] = $field['value'];
            if (strcasecmp($field['key'], 'PredefinedType') === 0) {
                $enumValues[] = $field['value'];
            }
        }
        if (array_filter($enumValues, static fn ($value): bool => is_string($value) && strtoupper(trim($value)) === 'AIRCONDITIONINGUNIT') !== []) {
            return 'air_conditioner';
        }
        $classifications = [];
        foreach (is_array($rawClassifications) && array_is_list($rawClassifications) ? $rawClassifications : [] as $value) {
            if (($safe = $this->scalar($value)) !== null) {
                $classifications[] = $safe;
            }
        }
        $text = implode(' ', [...$identity,
            ...array_values(array_diff_key($row['attributes'], array_flip(['storey', 'description']))), ...$classifications]);
        foreach ([$text, $row['name'] ?? ''] as $source) {
            if (preg_match('/'.self::airConditionerUnitPattern().'/iu', $source) === 1
                && preg_match('/'.self::airConditionerUnitReferencePattern().'/iu', $source) !== 1) {
                return 'air_conditioner';
            }
            foreach (['air_conditioner', 'fan', 'pump', 'boiler', 'chiller'] as $kind) {
                if (preg_match('/'.self::equipmentPattern($kind).'/iu', $source) === 1
                    && preg_match('/'.self::equipmentReferencePattern($kind).'/iu', $source) !== 1) {
                    return $kind;
                }
            }
        }
        return 'unknown';
    }
}
