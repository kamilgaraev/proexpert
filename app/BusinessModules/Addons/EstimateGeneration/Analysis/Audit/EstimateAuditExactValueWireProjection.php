<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EstimateAuditExactValueWireProjection
{
    public const CONTRACT = 'estimate-audit-exact-values:v1';

    private const MAX_CANDIDATES = 2048;

    private const MAX_INDEX_BYTES = 2097152;

    public static function forAudit(array $payload): array
    {
        if (($payload['contract_version'] ?? null) === 'estimate-auditor:v4') {
            if (array_key_exists('_wire', $payload) || array_key_exists('_tables', $payload) || array_key_exists('_key_wire', $payload)) {
                return $payload;
            }
            $original = self::project(EstimateAuditTableWireProjection::forAudit($payload));
            $aliased = self::project(EstimateAuditTableWireProjection::forAudit(EstimateAuditFieldWireProjection::forAudit($payload)));

            return strlen(CanonicalPipelineJson::encode($aliased)) < strlen(CanonicalPipelineJson::encode($original))
                ? $aliased : $original;
        }

        return in_array($payload['contract_version'] ?? null, ['estimate-auditor:v2', 'estimate-auditor:v3'], true)
            ? self::project(EstimateAuditTableWireProjection::forAudit($payload)) : $payload;
    }

    public static function project(array $payload): array
    {
        if (array_key_exists('_wire', $payload) || array_key_exists('_v', $payload)) {
            return $payload;
        }
        $candidates = [];
        $bytes = 0;
        $safe = true;
        self::collect($payload, $candidates, $bytes, $safe);
        if (! $safe) {
            return $payload;
        }
        $selected = array_filter($candidates, self::profitable(...));
        if ($selected === []) {
            return $payload;
        }
        $uses = [];
        self::replace($payload, $selected, $uses);
        foreach ($selected as $key => $candidate) {
            $candidate['count'] = $uses[$key] ?? 0;
            if (! self::profitable($candidate)) {
                unset($selected[$key]);
            }
        }
        if ($selected === []) {
            return $payload;
        }
        $values = [];
        foreach ($selected as &$candidate) {
            $candidate['index'] = count($values);
            $values[] = $candidate['value'];
        }
        unset($candidate);
        $uses = [];
        $projected = self::replace($payload, $selected, $uses);
        $projected['_wire'] = self::CONTRACT;
        $projected['_v'] = $values;

        return strlen(CanonicalPipelineJson::encode($projected)) < strlen(CanonicalPipelineJson::encode($payload))
            ? $projected : $payload;
    }

    public static function prompt(): string
    {
        return 'Если audit_input._wire="'.self::CONTRACT.'", объект с единственным полем _ref заменяет точное полное значение по JSON Pointer /_v/N в audit_input._v. '
            .'Сначала восстанови эти значения; словарь содержит полные исходные значения, включая неизвестные поля, порядок списков и обычные ссылки *_ref. '
            .'Все ссылки локальны этому audit_input; audit_input._v не является дополнительными работами или источниками. ';
    }

    private static function collect(mixed $value, array &$candidates, int &$bytes, bool &$safe, int $depth = 0): void
    {
        if ($depth > 64 || (is_array($value) && array_keys($value) === ['_ref'])) {
            $safe = false;

            return;
        }
        $encoded = self::encoded($value);
        if ($encoded !== null) {
            $key = hash('sha256', $encoded);
            if (isset($candidates[$key])) {
                if ($candidates[$key]['encoded'] !== $encoded) {
                    $safe = false;

                    return;
                }
                $candidates[$key]['count']++;
            } elseif (count($candidates) < self::MAX_CANDIDATES && $bytes + strlen($encoded) <= self::MAX_INDEX_BYTES) {
                $candidates[$key] = ['encoded' => $encoded, 'value' => $value, 'count' => 1, 'index' => count($candidates)];
                $bytes += strlen($encoded);
            }
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::collect($child, $candidates, $bytes, $safe, $depth + 1);
                if (! $safe) {
                    return;
                }
            }
        }
    }

    private static function replace(mixed $value, array $selected, array &$uses): mixed
    {
        $encoded = self::encoded($value);
        if ($encoded !== null) {
            $key = hash('sha256', $encoded);
            if (isset($selected[$key]) && $selected[$key]['encoded'] === $encoded) {
                $uses[$key] = ($uses[$key] ?? 0) + 1;

                return ['_ref' => '/_v/'.$selected[$key]['index']];
            }
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::replace($child, $selected, $uses);
            }
        }

        return $value;
    }

    private static function encoded(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_array($value)) || (is_array($value) && ($value === [] || count($value) > 64))
            || (is_string($value) && (strlen($value) < 64 || strlen($value) > 16384))) {
            return null;
        }
        $encoded = CanonicalPipelineJson::encode([$value]);
        $encoded = substr($encoded, 1, -1);

        return strlen($encoded) >= 64 && strlen($encoded) <= 16384 ? $encoded : null;
    }

    private static function profitable(array $candidate): bool
    {
        $bytes = strlen($candidate['encoded']);

        return ($bytes - 32) * $candidate['count'] > $bytes + 4;
    }
}
