<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EstimateAuditFieldWireProjection
{
    public const CONTRACT = 'estimate-audit-field-names:v1';

    private const RESERVED = ['_key_wire', '_key_names', '_wire', '_v', '_ref', '_tables', '_table', 'schemas', 'rows'];

    public static function forAudit(array $payload): array
    {
        if (($payload['contract_version'] ?? null) !== 'estimate-auditor:v4'
            || array_key_exists('_key_wire', $payload) || array_key_exists('_key_names', $payload)) {
            return $payload;
        }
        $counts = [];
        if (! self::collect($payload, $counts)) {
            return $payload;
        }
        uksort($counts, static fn (string $left, string $right): int => ($counts[$right] * strlen($right)) <=> ($counts[$left] * strlen($left)) ?: strcmp($left, $right));
        $aliases = $names = [];
        $bytes = 0;
        foreach ($counts as $name => $count) {
            $alias = '$'.count($names);
            $encodedName = strlen(CanonicalPipelineJson::encode([$name])) - 2;
            $encodedAlias = strlen($alias) + 2;
            if (($encodedName - $encodedAlias) * $count <= $encodedName + 1) {
                continue;
            }
            if (count($names) >= 512 || $bytes + $encodedName + 1 > 65536) {
                break;
            }
            $aliases[$name] = $alias;
            $names[] = $name;
            $bytes += $encodedName + 1;
        }
        if ($names === []) {
            return $payload;
        }
        $projected = self::replace($payload, $aliases);
        $projected['_key_wire'] = self::CONTRACT;
        $projected['_key_names'] = $names;

        return strlen(CanonicalPipelineJson::encode($projected)) < strlen(CanonicalPipelineJson::encode($payload))
            ? $projected : $payload;
    }

    public static function prompt(): string
    {
        return 'Если audit_input._key_wire="'.self::CONTRACT.'", имена полей вида $N означают точное исходное имя из audit_input._key_names[N], номер с нуля. '
            .'После восстановления _ref и таблиц _table восстанови имена полей во всех вложенных объектах, затем разрешай обычные ссылки *_ref по исходным именам. '
            .'Строковые значения вида $N остаются строками; словарь имён не является работами или источниками. ';
    }

    private static function collect(mixed $node, array &$counts, int $depth = 0): bool
    {
        if ($depth > 64) {
            return false;
        }
        if (! is_array($node)) {
            return true;
        }
        foreach ($node as $name => $child) {
            if (is_string($name) && preg_match('/\A\$[0-9]+\z/D', $name) === 1) {
                return false;
            }
            if ($depth > 0 && is_string($name) && strlen($name) >= 8 && strlen($name) <= 256
                && ! in_array($name, self::RESERVED, true)) {
                if (isset($counts[$name])) {
                    $counts[$name]++;
                } elseif (count($counts) < 2048) {
                    $counts[$name] = 1;
                }
            }
            if (! self::collect($child, $counts, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private static function replace(mixed $node, array $aliases, int $depth = 0): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        $projected = [];
        foreach ($node as $name => $child) {
            $key = $depth > 0 && is_string($name) ? ($aliases[$name] ?? $name) : $name;
            $projected[$key] = self::replace($child, $aliases, $depth + 1);
        }

        return $projected;
    }
}
