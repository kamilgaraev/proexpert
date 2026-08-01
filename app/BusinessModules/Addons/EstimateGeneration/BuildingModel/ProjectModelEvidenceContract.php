<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

/** The only document-evidence shapes that may confirm a project-model value. */
final class ProjectModelEvidenceContract
{
    /** @param array<string,mixed> $evidence @param array<string,mixed> $candidate */
    public static function confirms(string $source, array $evidence, array $candidate, array $locator): bool
    {
        if (! self::trustedEnvelope($source, $evidence) || ! self::trustedLocator($source, $evidence['source_ref'] ?? null, $evidence['locator'] ?? null)) {
            return false;
        }

        try {
            if (! hash_equals(ProjectModelLocatorFingerprint::for(self::map($evidence['locator'] ?? null)), ProjectModelLocatorFingerprint::for($locator))) {
                return false;
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        $canonical = self::candidateValue($source, self::map($evidence['value'] ?? null));
        if ($canonical === null) {
            return false;
        }

        try {
            return hash_equals(ProjectModelValueFingerprint::for($candidate), ProjectModelValueFingerprint::for($canonical));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @param array<string,mixed> $evidence */
    public static function trustedEnvelope(string $source, array $evidence): bool
    {
        return match ($source) {
            'cad' => ($evidence['type'] ?? null) === 'measured'
                && ($evidence['source_type'] ?? null) === 'document_unit'
                && ($evidence['producer_name'] ?? null) === 'pdf_geometry'
                && ($evidence['producer_version'] ?? null) === 'extractor:v1',
            'table' => ($evidence['type'] ?? null) === 'extracted'
                && ($evidence['source_type'] ?? null) === 'document_unit'
                && ($evidence['producer_name'] ?? null) === 'ocr_fact_extractor'
                && ($evidence['producer_version'] ?? null) === 'extractor:v1',
            'explicit_dimension' => ($evidence['type'] ?? null) === 'extracted'
                && ($evidence['source_type'] ?? null) === 'document_unit'
                && ($evidence['producer_name'] ?? null) === 'drawing_analyzer'
                && ($evidence['producer_version'] ?? null) === 'model:v2',
            'reconciled_geometry' => ($evidence['type'] ?? null) === 'measured'
                && ($evidence['source_type'] ?? null) === 'document_unit'
                && ($evidence['producer_name'] ?? null) === 'pdf_geometry'
                && ($evidence['producer_version'] ?? null) === 'extractor:v1',
            default => false,
        };
    }

    public static function trustedLocator(string $source, mixed $sourceRef, mixed $locator): bool
    {
        if (! is_string($sourceRef) || preg_match('/^document:([1-9][0-9]*)$/D', $sourceRef, $matches) !== 1
            || ! is_array($locator) || array_is_list($locator)
            || ! is_int($locator['document_id'] ?? null) || $locator['document_id'] !== (int) $matches[1]
            || ! is_int($locator['unit_index'] ?? null) || $locator['unit_index'] < 0
            || ! is_int($locator['page'] ?? null) || $locator['page'] !== $locator['unit_index']) {
            return false;
        }

        return match ($source) {
            'explicit_dimension' => is_string($locator['region_key'] ?? null) && preg_match('/^region:[a-f0-9]{64}$/D', $locator['region_key']) === 1
                && is_string($locator['element_key'] ?? null) && preg_match('/^element:[a-f0-9]{64}$/D', $locator['element_key']) === 1
                && is_array($locator['bbox'] ?? null) && array_is_list($locator['bbox']) && count($locator['bbox']) === 4,
            'table' => is_string($locator['cell'] ?? null) && preg_match('/^[A-Z]{1,3}[1-9][0-9]*$/D', $locator['cell']) === 1,
            'cad', 'reconciled_geometry' => is_string($locator['handle'] ?? null) && preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $locator['handle']) === 1,
            default => false,
        };
    }

    /** @param array<string,mixed> $mapped @return array<string,mixed>|null */
    public static function candidateValue(string $source, array $mapped): ?array
    {
        $key = $mapped['field_key'] ?? null;
        $value = $mapped['field_value'] ?? null;
        $unit = $mapped['unit'] ?? null;
        if (! is_string($key) || (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || (float) $value <= 0) {
            return null;
        }

        return match ($source) {
            'explicit_dimension', 'table' => $key === 'room_area' && $unit === 'm2'
                ? ['value' => (float) $value, 'unit' => 'm2'] : null,
            'cad', 'reconciled_geometry' => $key === 'dimension_value' && is_string($unit)
                && in_array($unit, ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true)
                ? ['value' => (float) $value, 'unit' => $unit] : null,
            default => null,
        };
    }

    /** @return array<string,mixed> */
    public static function map(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }
}
