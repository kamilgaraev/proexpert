<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;

final class AssistantBimIndexPredicate
{
    private const TABLE = 'design_ifc_model_elements';
    private const UNSAFE_VALUE = 'https?://|[[:alnum:].+_-]+@[[:alnum:]._-]+\.[[:alpha:]]{2,}|(token|secret|password)[[:space:]]*[:=]';

    public function apply(Builder $scope, array $filters, array $categories): void
    {
        $table = self::TABLE;
        $properties = $this->engineering();
        $attributeValues = [];
        foreach (['predefined_type', 'object_type', 'type_name', 'type_predefined_type', 'type_object_type'] as $field) {
            $expression = "{$table}.properties #>> '{_ifc,{$field}}'";
            $attributeValues[] = "CASE WHEN {$expression} !~* '".self::UNSAFE_VALUE."' THEN left({$expression}, 255) ELSE '' END";
        }
        $attributes = "concat_ws(' ', ".implode(', ', $attributeValues).')';
        $classifications = $this->labels('classifications');
        $equipmentQuery = (new AssistantBimIndexProjection)->queryEquipment(trim($filters['element_query'] ?? ''));
        $classifier = $table.'.source_equipment_kind';
        foreach (['material_query' => $this->labels("properties -> 'materials'"),
            'classification_query' => $classifications, 'storey_query' => "{$table}.properties #>> '{_ifc,storey,name}'"] as $filter => $expression) {
            if (! empty($filters[$filter])) {
                $this->text($scope, $expression, $filters[$filter]);
            }
        }
        if (! empty($filters['equipment_kind'])) {
            $scope->where($classifier, $filters['equipment_kind']);
        }
        if (! empty($filters['property_name'])) {
            $scope->whereRaw('EXISTS ('.$this->leaves()." AND lower(p.key) LIKE ?".
                (($filters['property_value'] ?? null) !== null && $filters['property_value'] !== '' ? " AND lower(p.value #>> '{}') LIKE ?" : '').')',
                array_values(array_filter(['%'.addcslashes(mb_strtolower($filters['property_name']), '%_\\').'%',
                    ($filters['property_value'] ?? null) === null || $filters['property_value'] === '' ? null : '%'.addcslashes(mb_strtolower($filters['property_value']), '%_\\').'%'], static fn ($value): bool => $value !== null)));
        }
        $term = trim($filters['element_query'] ?? '');
        if ($term !== '') {
            $kind = $equipmentQuery;
            if ($kind !== null) {
                $scope->where($classifier, $kind);
            } else {
                $scope->where(function (Builder $search) use ($categories, $table, $term, $properties, $attributes, $classifications): void {
                    if ($categories !== []) {
                        $search->whereIn($table.'.category', $categories);
                    } else {
                        $this->text($search, "concat_ws(' ', {$table}.name, {$table}.global_id, {$table}.category, {$properties}, {$attributes}, {$classifications}, ".$this->labels("properties -> 'materials'").')', $term);
                    }
                });
            }
        }
    }

    private function leaves(): string
    {
        $table = self::TABLE;
        $pattern = str_replace("'", "''", AssistantBimIndexProjection::propertyPattern());
        $unsafe = str_replace("'", "''", self::UNSAFE_VALUE);
        return "SELECT 1 FROM jsonb_path_query(COALESCE({$table}.properties, '{}'::jsonb), '$.**') AS node(value) CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(node.value) = 'object' THEN node.value ELSE '{}'::jsonb END) AS p(key, value) WHERE p.key ~* '{$pattern}' AND jsonb_typeof(p.value) IN ('string', 'number', 'boolean') AND (p.value #>> '{}') !~* '{$unsafe}'";
    }

    private function engineering(): string
    {
        $leaves = $this->leaves();
        return '(COALESCE(('.str_replace('SELECT 1', "SELECT string_agg(left(p.value #>> '{}', 255), ' ')", $leaves)."), ''))";
    }

    private function labels(string $column): string
    {
        $source = self::TABLE.'.'.$column;
        $unsafe = str_replace("'", "''", self::UNSAFE_VALUE);
        return "COALESCE((SELECT string_agg(left(label.value #>> '{}', 255), ' ') FROM jsonb_array_elements(CASE WHEN jsonb_typeof({$source}) = 'array' THEN {$source} ELSE '[]'::jsonb END) label(value) WHERE jsonb_typeof(label.value) IN ('string','number','boolean') AND (label.value #>> '{}') !~* '{$unsafe}'), '')";
    }

    private function text(Builder $scope, string $expression, string $term): void
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower(trim($term))), -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
        foreach ($tokens as $token) {
            $stem = mb_strlen($token) > 4 && preg_match('/^[а-я]+$/u', $token) === 1
                ? (preg_replace('/(?:ого|его|ому|ему|ыми|ими|иях|ая|яя|ой|ую|юю|ые|ых|ие|ий|ии|ям|ей|ам|ов|ах|ях|а|я|ы|и|у|е)$/u', '', $token) ?? $token) : $token;
            $scope->whereRaw("replace(lower(COALESCE(({$expression}), '')), 'ё', 'е') LIKE ?", ['%'.addcslashes($stem, '%_\\').'%']);
        }
    }
}
