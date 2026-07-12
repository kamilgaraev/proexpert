<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use InvalidArgumentException;

final readonly class GeometryConfirmationData
{
    /** @param list<array<string, mixed>> $scaleEvidence @param list<array<string, mixed>> $elements */
    public function __construct(
        public string $sourceFingerprint,
        public string $geometryPayloadSha256,
        public array $scaleEvidence,
        public array $elements,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::exactKeys($data, ['schema_version', 'source_fingerprint', 'geometry_payload_sha256', 'scale_evidence', 'elements']);
        if ($data['schema_version'] !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $data['source_fingerprint']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $data['geometry_payload_sha256']) !== 1
            || ! self::nonEmptyList($data['scale_evidence']) || ! self::nonEmptyList($data['elements'])) {
            throw new InvalidArgumentException('geometry_confirmation_invalid');
        }
        foreach ($data['scale_evidence'] as $evidence) {
            self::assertScaleEvidence($evidence);
        }
        $keys = [];
        $semanticOwners = [];
        $walls = [];
        $openingSignatures = [];
        foreach ($data['elements'] as $element) {
            self::assertElement($element);
            if (isset($keys[$element['key']])) {
                throw new InvalidArgumentException('geometry_confirmation_key_duplicate');
            }
            $keys[$element['key']] = true;
            if ($element['type'] === 'room') {
                self::claim($semanticOwners, $element['boundary_handle'], $element['key']);
            } elseif ($element['type'] === 'wall') {
                $walls[$element['key']] = $element['segment_handles'];
                foreach ($element['segment_handles'] as $handle) {
                    self::claim($semanticOwners, $handle, $element['key']);
                }
            }
        }
        foreach ($data['elements'] as $element) {
            if ($element['type'] !== 'opening') {
                continue;
            }
            $segments = $walls[$element['wall_key']] ?? null;
            if ($segments === null || array_diff($element['boundary_handles'], $segments) !== []) {
                throw new InvalidArgumentException('geometry_confirmation_opening_reference_invalid');
            }
            $signature = $element['wall_key'].'|'.implode('|', $element['boundary_handles']);
            if (isset($openingSignatures[$signature])) {
                throw new InvalidArgumentException('geometry_confirmation_opening_duplicate');
            }
            $openingSignatures[$signature] = true;
        }

        return new self($data['source_fingerprint'], $data['geometry_payload_sha256'], $data['scale_evidence'], $data['elements']);
    }

    private static function assertScaleEvidence(mixed $evidence): void
    {
        if (! is_array($evidence) || ! is_string($evidence['role'] ?? null)) {
            throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid');
        }
        $keys = match ($evidence['role']) {
            'dimension' => ['role', 'value_handle', 'entity_handle', 'point_indexes'],
            'unit_declaration', 'cad_header' => ['role', 'value_handle'],
            'measured_segment' => ['role', 'entity_handle', 'point_indexes', 'real_world_value', 'unit'],
            default => throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid'),
        };
        self::exactKeys($evidence, $keys);
        if (isset($evidence['entity_handle']) && ! self::reference($evidence['entity_handle'])) {
            throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid');
        }
        if (isset($evidence['value_handle']) && ! self::reference($evidence['value_handle'])) {
            throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid');
        }
        if (isset($evidence['point_indexes']) && ! self::pointIndexes($evidence['point_indexes'])) {
            throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid');
        }
        if ($evidence['role'] === 'measured_segment'
            && ((! is_int($evidence['real_world_value']) && ! is_float($evidence['real_world_value']))
                || ! is_finite((float) $evidence['real_world_value']) || (float) $evidence['real_world_value'] <= 0
                || ! in_array($evidence['unit'], ['mm', 'cm', 'm', 'in', 'ft'], true))) {
            throw new InvalidArgumentException('geometry_confirmation_scale_evidence_invalid');
        }
    }

    private static function assertElement(mixed $element): void
    {
        if (! is_array($element) || ! self::reference($element['key'] ?? null)
            || ! in_array($element['type'] ?? null, ['room', 'wall', 'opening'], true)) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        $keys = match ($element['type']) {
            'room' => ['key', 'type', 'boundary_handle'],
            'wall' => ['key', 'type', 'segment_handles'],
            'opening' => ['key', 'type', 'wall_key', 'opening_type', 'boundary_handles', 'dimension_handle'],
        };
        self::exactKeys($element, $keys);
        if ($element['type'] === 'room' && ! self::reference($element['boundary_handle'])) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        if ($element['type'] === 'wall' && ! self::references($element['segment_handles'])) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        if ($element['type'] === 'opening' && (! self::reference($element['wall_key'])
            || ! in_array($element['opening_type'], ['door', 'window', 'gate', 'other'], true)
            || ! self::references($element['boundary_handles']) || count($element['boundary_handles']) !== 2
            || ! self::reference($element['dimension_handle']))) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
    }

    private static function claim(array &$owners, string $handle, string $key): void
    {
        if (isset($owners[$handle])) {
            throw new InvalidArgumentException('geometry_confirmation_semantic_collision');
        }
        $owners[$handle] = $key;
    }

    private static function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('geometry_confirmation_fields_invalid');
        }
    }

    private static function nonEmptyList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [];
    }

    private static function references(mixed $value): bool
    {
        return self::nonEmptyList($value) && array_filter($value, self::reference(...)) === $value
            && array_values(array_unique($value)) === $value;
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512;
    }

    private static function pointIndexes(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) === 2
            && is_int($value[0]) && is_int($value[1]) && $value[0] >= 0 && $value[1] >= 0 && $value[0] !== $value[1];
    }
}
