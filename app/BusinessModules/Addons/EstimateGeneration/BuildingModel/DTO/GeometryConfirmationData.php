<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GeometryConfirmationData
{
    /** @param list<string> $scaleEvidenceHandles @param list<array<string, mixed>> $elements */
    public function __construct(
        public string $sourceFingerprint,
        public string $geometryPayloadSha256,
        public string $confirmationSource,
        public string $reviewerRef,
        public string $confirmedAt,
        public float $metersPerUnit,
        public array $scaleEvidenceHandles,
        public array $elements,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $required = ['schema_version', 'source_fingerprint', 'geometry_payload_sha256', 'confirmation_source',
            'reviewer_ref', 'confirmed_at', 'meters_per_unit', 'scale_evidence_handles', 'elements'];
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);
        if ($keys !== $required || $data['schema_version'] !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $data['source_fingerprint']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $data['geometry_payload_sha256']) !== 1
            || ! in_array($data['confirmation_source'], ['user_review', 'dimension_evidence', 'cad_unit_review'], true)
            || ! is_string($data['reviewer_ref']) || trim($data['reviewer_ref']) === ''
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $data['confirmed_at']) === false
            || ! is_numeric($data['meters_per_unit']) || (float) $data['meters_per_unit'] <= 0
            || ! self::references($data['scale_evidence_handles'])
            || ! is_array($data['elements']) || ! array_is_list($data['elements']) || $data['elements'] === []) {
            throw new InvalidArgumentException('geometry_confirmation_invalid');
        }
        foreach ($data['elements'] as $element) {
            self::assertElement($element);
        }

        return new self(
            $data['source_fingerprint'], $data['geometry_payload_sha256'], $data['confirmation_source'],
            $data['reviewer_ref'], $data['confirmed_at'], (float) $data['meters_per_unit'],
            $data['scale_evidence_handles'], $data['elements'],
        );
    }

    private static function assertElement(mixed $element): void
    {
        if (! is_array($element) || ! is_string($element['key'] ?? null) || ! is_string($element['entity_handle'] ?? null)
            || ! in_array($element['type'] ?? null, ['room', 'wall', 'opening'], true)) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        $allowed = match ($element['type']) {
            'room' => ['key', 'type', 'entity_handle'],
            'wall' => ['key', 'type', 'entity_handle', 'point_indexes'],
            'opening' => ['key', 'type', 'entity_handle', 'wall_key', 'opening_type', 'offset', 'width', 'height', 'evidence_handles'],
        };
        $keys = array_keys($element);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        if ($element['type'] === 'wall' && (! is_array($element['point_indexes']) || count($element['point_indexes']) !== 2
            || ! is_int($element['point_indexes'][0]) || ! is_int($element['point_indexes'][1]))) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
        if ($element['type'] === 'opening' && (! is_string($element['wall_key'])
            || ! in_array($element['opening_type'], ['door', 'window', 'gate', 'other'], true)
            || ! self::positive($element['width']) || ! self::positive($element['height'])
            || ! is_numeric($element['offset']) || (float) $element['offset'] < 0
            || ! self::references($element['evidence_handles']))) {
            throw new InvalidArgumentException('geometry_confirmation_element_invalid');
        }
    }

    private static function references(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== []
            && array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '') === $value
            && array_values(array_unique($value)) === $value;
    }

    private static function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
