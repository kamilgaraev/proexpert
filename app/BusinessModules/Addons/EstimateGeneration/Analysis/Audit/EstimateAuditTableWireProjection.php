<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EstimateAuditTableWireProjection
{
    public const CONTRACT = 'estimate-audit-tables:v1';

    public static function forAudit(array $payload): array
    {
        if (($payload['contract_version'] ?? null) !== 'estimate-auditor:v3'
            || array_key_exists('_tables', $payload) || array_key_exists('_wire', $payload)
            || ! self::safe($payload)) {
            return $payload;
        }
        $projected = self::project($payload);
        $projected['_tables'] = self::CONTRACT;

        return strlen(CanonicalPipelineJson::encode($projected)) < strlen(CanonicalPipelineJson::encode($payload))
            ? $projected : $payload;
    }

    public static function prompt(): string
    {
        return 'Если audit_input._tables="'.self::CONTRACT.'", объект с единственным ключом _table кодирует исходный список объектов. '
            .'В _table.schemas находятся списки имён полей; в каждой строке _table.rows первый элемент — номер схемы с нуля, остальные элементы — значения полей этой схемы по порядку. '
            .'Восстанови все строки в исходном порядке, включая null, неизвестные поля и вложенные таблицы. Отсутствующее в схеме поле отсутствует в исходной строке. '
            .'Сначала восстанови точные значения _ref из audit_input._v, затем вложенные _table, затем обычные ссылки *_ref. Служебные схемы не являются работами или источниками. ';
    }

    private static function safe(mixed $value, int $depth = 0): bool
    {
        if ($depth > 64 || (is_array($value) && array_keys($value) === ['_table'])) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (! self::safe($child, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function project(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::project($child);
        }
        if (! array_is_list($value) || count($value) < 3 || count($value) > 20000) {
            return $value;
        }
        $schemas = $indices = $rows = [];
        foreach ($value as $record) {
            if (! is_array($record) || array_is_list($record) || count($record) > 64) {
                return $value;
            }
            $columns = array_keys($record);
            if (array_filter($columns, static fn (mixed $key): bool => ! is_string($key)) !== []) {
                return $value;
            }
            sort($columns, SORT_STRING);
            $signature = CanonicalPipelineJson::encode($columns);
            if (! isset($indices[$signature])) {
                if (count($schemas) >= 128) {
                    return $value;
                }
                $indices[$signature] = count($schemas);
                $schemas[] = $columns;
            }
            $row = [$indices[$signature]];
            foreach ($columns as $column) {
                $row[] = $record[$column];
            }
            $rows[] = $row;
        }
        $table = ['_table' => ['schemas' => $schemas, 'rows' => $rows]];

        return strlen(CanonicalPipelineJson::encode([$table])) < strlen(CanonicalPipelineJson::encode([$value]))
            ? $table : $value;
    }
}
