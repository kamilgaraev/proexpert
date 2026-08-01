<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final class ProjectSheetAnalysisValidator
{
    private const SHEET_ROLES = ['plan', 'section', 'elevation', 'specification', 'visual', 'unknown'];

    private const FACT_TYPES = ['room', 'wall', 'opening', 'axis', 'dimension_chain', 'sanitary_fixture', 'furniture', 'structural_element', 'table', 'cross_sheet_link'];

    /** @param array<string, mixed> $data @param list<string> $evidenceKeys */
    public static function assertValid(array $data, array $evidenceKeys): void
    {
        if (! self::hasExactKeys($data, ['schema_version', 'sheet_role', 'facts'])
            || ($data['schema_version'] ?? null) !== ProjectSheetAnalysisData::SCHEMA_VERSION
            || ! is_string($data['sheet_role'] ?? null) || ! in_array($data['sheet_role'], self::SHEET_ROLES, true)
            || ! is_array($data['facts'] ?? null) || ! array_is_list($data['facts']) || count($data['facts']) > 500) {
            throw new VisionContractException('invalid_project_sheet_analysis');
        }

        $keys = [];
        foreach ($data['facts'] as $fact) {
            if (! is_array($fact)) {
                throw new VisionContractException('invalid_project_sheet_fact');
            }
            self::assertFact($fact, $evidenceKeys);
            $keys[] = $fact['key'];
        }
        if (count($keys) !== count(array_unique($keys))) {
            throw new VisionContractException('duplicate_project_sheet_fact_key');
        }
    }

    /** @param array<string, mixed> $fact @param list<string> $evidenceKeys */
    private static function assertFact(array $fact, array $evidenceKeys): void
    {
        if (! self::hasExactKeys($fact, ['key', 'type', 'evidence_ref', 'polygon', 'confidence', 'value', 'unit'])
            || ! is_string($fact['key'] ?? null) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $fact['key']) !== 1
            || ! is_string($fact['type'] ?? null) || ! in_array($fact['type'], self::FACT_TYPES, true)
            || ! is_string($fact['evidence_ref'] ?? null) || ! in_array($fact['evidence_ref'], $evidenceKeys, true)
            || ! is_array($fact['polygon'] ?? null) || ! is_numeric($fact['confidence'] ?? null)
            || ! is_finite((float) $fact['confidence']) || (float) $fact['confidence'] < 0 || (float) $fact['confidence'] > 1
            || ! is_array($fact['value'] ?? null) || (! is_string($fact['unit']) && $fact['unit'] !== null)) {
            throw new VisionContractException('invalid_project_sheet_fact');
        }
        self::assertNormalizedGeometry($fact['polygon']);
        self::assertTypedValue($fact['value'], $fact['unit']);
    }

    /** @param array<mixed> $polygon */
    private static function assertNormalizedGeometry(array $polygon): void
    {
        if (count($polygon) < 2 || count($polygon) > 64) {
            throw new VisionContractException('invalid_project_sheet_geometry');
        }
        $points = [];
        foreach ($polygon as $point) {
            if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])
                || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])
                || (float) $point[0] < 0 || (float) $point[0] > 1 || (float) $point[1] < 0 || (float) $point[1] > 1) {
                throw new VisionContractException('invalid_project_sheet_geometry');
            }
            $points[] = sprintf('%.12F:%.12F', (float) $point[0], (float) $point[1]);
        }
        if (count($points) !== count(array_unique($points))) {
            throw new VisionContractException('invalid_project_sheet_geometry');
        }
    }

    /** @param array<string, mixed> $value */
    private static function assertTypedValue(array $value, mixed $unit): void
    {
        if (! self::hasExactKeys($value, ['type', 'data']) || ! is_string($value['type'] ?? null)
            || ! in_array($value['type'], ['number', 'string', 'boolean', 'enum', 'unknown'], true)) {
            throw new VisionContractException('invalid_project_sheet_value');
        }
        if ($value['type'] === 'unknown') {
            if ($value['data'] !== null || $unit !== null) {
                throw new VisionContractException('invalid_unknown_project_sheet_value');
            }

            return;
        }
        $valid = match ($value['type']) {
            'number' => is_numeric($value['data']) && is_finite((float) $value['data']),
            'string', 'enum' => is_string($value['data']) && mb_strlen($value['data']) <= 500 && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value['data']) !== 1,
            'boolean' => is_bool($value['data']),
        };
        if (! $valid || ($unit !== null && (mb_strlen($unit) > 32 || preg_match('/^[A-Za-z0-9.%²³/_ -]+$/u', $unit) !== 1))) {
            throw new VisionContractException('invalid_project_sheet_value');
        }
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
