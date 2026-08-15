<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final class ProjectSheetAnalysisValidator
{
    private const ROLE_FACT_TYPES = [
        'plan' => ['room', 'wall', 'opening', 'axis', 'dimension_chain', 'area', 'level', 'material', 'finish_zone', 'engineering_element', 'sanitary_fixture', 'furniture', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
        'section' => ['opening', 'axis', 'dimension_chain', 'elevation', 'level', 'area', 'structural_element', 'roof_geometry', 'material', 'finish_zone', 'engineering_element', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
        'facade' => ['opening', 'axis', 'dimension_chain', 'elevation', 'level', 'area', 'structural_element', 'roof_geometry', 'material', 'finish_zone', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
        'explication' => ['room', 'area', 'level', 'material', 'finish_zone', 'table', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
        'specification' => ['table', 'structural_element', 'material', 'equipment', 'quantity', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
        'unknown' => ['opening', 'axis', 'dimension_chain', 'elevation', 'level', 'area', 'structural_element', 'roof_geometry', 'material', 'finish_zone', 'engineering_element', 'equipment', 'quantity', 'room', 'wall', 'table', 'note', 'cross_sheet_link', 'technology_candidate', 'assumption', 'risk', 'unknown', 'recommendation'],
    ];

    /** @param array<string, mixed> $data @param list<string> $evidenceKeys @param list<string> $nativeReferences */
    public static function assertValid(array $data, array $evidenceKeys, int $maxFacts = 500, array $nativeReferences = []): void
    {
        if ($maxFacts < 1 || $maxFacts > 500
            || ! self::hasExactKeys($data, ['contractVersion', 'role', 'facts'])
            || ($data['contractVersion'] ?? null) !== ProjectSheetAnalysisData::CONTRACT_VERSION
            || ! is_string($data['role'] ?? null) || ! array_key_exists($data['role'], self::ROLE_FACT_TYPES)
            || ! is_array($data['facts'] ?? null) || ! array_is_list($data['facts']) || count($data['facts']) > $maxFacts) {
            throw new VisionContractException('invalid_project_sheet_analysis');
        }

        $entities = [];
        foreach ($data['facts'] as $fact) {
            if (! is_array($fact)) {
                throw new VisionContractException('invalid_project_sheet_fact');
            }
            self::assertFact($fact, $data['role'], $evidenceKeys, $nativeReferences);
            $entities[] = $fact['entityKey']."\0".$fact['factType'];
        }
        if (count($entities) !== count(array_unique($entities))) {
            throw new VisionContractException('duplicate_project_sheet_fact_key');
        }
    }

    /** @return list<string> */
    public static function factTypes(string $role): array
    {
        return self::ROLE_FACT_TYPES[$role] ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $evidenceKeys
     * @param  list<string>  $nativeReferences
     * @return array{role: string, facts: list<array<string, mixed>>, quarantined: list<array{section: string, index: int, reason: string}>}
     */
    public static function normalizeProvider(array $data, array $evidenceKeys, int $maxFacts = 500, array $nativeReferences = []): array
    {
        if ($maxFacts < 1 || $maxFacts > 500
            || ! self::hasExactKeys($data, ['contractVersion', 'role', 'facts'])
            || ($data['contractVersion'] ?? null) !== ProjectSheetAnalysisData::CONTRACT_VERSION
            || ! is_string($data['role'] ?? null) || ! array_key_exists($data['role'], self::ROLE_FACT_TYPES)
            || ! is_array($data['facts'] ?? null) || ! array_is_list($data['facts']) || count($data['facts']) > $maxFacts) {
            throw new VisionContractException('invalid_project_sheet_analysis');
        }

        $facts = [];
        $quarantined = [];
        $entities = [];
        foreach ($data['facts'] as $index => $fact) {
            try {
                if (! is_array($fact)) {
                    throw new VisionContractException('invalid_project_sheet_fact');
                }
                self::assertFact($fact, $data['role'], $evidenceKeys, $nativeReferences);
                $factIdentity = $fact['entityKey']."\0".$fact['factType'];
                if (isset($entities[$factIdentity])) {
                    throw new VisionContractException('duplicate_project_sheet_fact_key');
                }
                $entities[$factIdentity] = true;
                $facts[] = $fact;
            } catch (VisionContractException $exception) {
                $quarantined[] = ['section' => 'facts', 'index' => $index, 'reason' => $exception->reason];
            }
        }

        return ['role' => $data['role'], 'facts' => $facts, 'quarantined' => $quarantined];
    }

    /** @param array<string, mixed> $fact @param list<string> $evidenceKeys @param list<string> $nativeReferences */
    private static function assertFact(array $fact, string $role, array $evidenceKeys, array $nativeReferences): void
    {
        if (! self::hasExactKeys($fact, ['entityKey', 'factType', 'value', 'unit', 'evidenceRef', 'sourcePolygonOrNativeRef', 'confidence', 'contractVersion'])
            || ! is_string($fact['entityKey'] ?? null) || preg_match('~^[a-z0-9][a-z0-9._:-]{0,79}$~', $fact['entityKey']) !== 1
            || ! is_string($fact['factType'] ?? null) || preg_match('~^[a-z0-9][a-z0-9._:-]{0,79}$~', $fact['factType']) !== 1
            || ! is_array($fact['value'] ?? null) || (! is_string($fact['unit'] ?? null) && ($fact['unit'] ?? null) !== null)
            || ! is_string($fact['evidenceRef'] ?? null) || ! in_array($fact['evidenceRef'], $evidenceKeys, true)
            || ! is_numeric($fact['confidence'] ?? null) || ! is_finite((float) $fact['confidence'])
            || (float) $fact['confidence'] < 0 || (float) $fact['confidence'] > 1
            || ($fact['contractVersion'] ?? null) !== ProjectSheetAnalysisData::CONTRACT_VERSION) {
            throw new VisionContractException('invalid_project_sheet_fact');
        }

        if (! in_array($fact['factType'], self::ROLE_FACT_TYPES[$role], true)) {
            throw new VisionContractException('unregistered_project_sheet_fact_type');
        }

        self::assertSourceReference($fact['sourcePolygonOrNativeRef'] ?? null, $nativeReferences);
        self::assertTypedValue($fact['value'], $fact['unit']);
    }

    /** @param list<string> $nativeReferences */
    private static function assertSourceReference(mixed $source, array $nativeReferences): void
    {
        if (is_string($source)) {
            if (mb_strlen($source) > 240 || preg_match('~^(?:pdf|image|cad|xlsx):(?!.*\\\\)[^\x00-\x1F]{1,220}$~u', $source) !== 1) {
                throw new VisionContractException('invalid_project_sheet_geometry');
            }
            if (! in_array($source, $nativeReferences, true)) {
                throw new VisionContractException('invalid_project_sheet_native_reference');
            }

            return;
        }
        if (! is_array($source) || count($source) < 2 || count($source) > 64) {
            throw new VisionContractException('invalid_project_sheet_geometry');
        }
        $points = [];
        foreach ($source as $point) {
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
            'number' => (is_int($value['data']) || is_float($value['data'])) && is_finite((float) $value['data']),
            'string', 'enum' => is_string($value['data']) && mb_strlen($value['data']) <= 500
                && preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', $value['data']) !== 1,
            'boolean' => is_bool($value['data']),
        };
        if (! $valid || ($unit !== null && (mb_strlen($unit) > 32 || preg_match('~^[\p{L}0-9.%²³/_ -]+$~u', $unit) !== 1))) {
            throw new VisionContractException('invalid_project_sheet_value');
        }
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
