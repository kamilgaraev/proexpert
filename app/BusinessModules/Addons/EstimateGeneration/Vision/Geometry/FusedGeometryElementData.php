<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

use InvalidArgumentException;

final readonly class FusedGeometryElementData
{
    public array $provenance;

    public function __construct(
        public string $key, public string $type, public array $geometry, public string $sourceType,
        public string $evidenceRef, public string $sourceFingerprint, public int $pageNumber,
        public string $coordinateSpace, public string $runtimeVersion, public string $modelVersion,
        public float $confidence, array $provenance = [],
    ) {
        if ($key === '' || ! in_array($type, ['room', 'wall', 'opening', 'engineering_element'], true)
            || ! in_array($sourceType, ['vector', 'vision'], true) || $evidenceRef === ''
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1 || $pageNumber < 1
            || $coordinateSpace === '' || $runtimeVersion === '' || $modelVersion === ''
            || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Fused geometry element is invalid.');
        }
        $this->assertGeometry($type, $geometry);
        $current = ['evidence_ref' => $evidenceRef, 'source_type' => $sourceType, 'source_fingerprint' => $sourceFingerprint, 'page_number' => $pageNumber, 'coordinate_space' => $coordinateSpace, 'runtime_version' => $runtimeVersion, 'model_version' => $modelVersion, 'confidence' => $confidence];
        $indexed = [];
        foreach ([...$provenance, $current] as $item) {
            if (! is_array($item) || array_keys($item) !== array_keys($current) || ! is_string($item['evidence_ref']) || $item['evidence_ref'] === '') {
                throw new InvalidArgumentException('Geometry provenance is invalid.');
            }
            if (! in_array($item['source_type'], ['vector', 'vision'], true)
                || ! is_string($item['source_fingerprint']) || preg_match('/^sha256:[a-f0-9]{64}$/', $item['source_fingerprint']) !== 1
                || ! is_int($item['page_number']) || $item['page_number'] < 1
                || ! is_string($item['coordinate_space']) || $item['coordinate_space'] === ''
                || ! is_string($item['runtime_version']) || $item['runtime_version'] === ''
                || ! is_string($item['model_version']) || $item['model_version'] === ''
                || (! is_int($item['confidence']) && ! is_float($item['confidence'])) || ! is_finite((float) $item['confidence'])
                || $item['confidence'] < 0 || $item['confidence'] > 1) {
                throw new InvalidArgumentException('Geometry provenance metadata is invalid.');
            }
            if (isset($indexed[$item['evidence_ref']]) && $indexed[$item['evidence_ref']] !== $item) {
                throw new InvalidArgumentException('Geometry evidence provenance conflicts.');
            }
            $indexed[$item['evidence_ref']] = $item;
        }
        ksort($indexed, SORT_STRING);
        $this->provenance = array_values($indexed);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function evidenceRefs(): array
    {
        return array_column($this->provenance, 'evidence_ref');
    }

    public function withProvenanceFrom(self $other): self
    {
        return new self($this->key, $this->type, $this->geometry, $this->sourceType, $this->evidenceRef, $this->sourceFingerprint, $this->pageNumber, $this->coordinateSpace, $this->runtimeVersion, $this->modelVersion, min($this->confidence, $other->confidence), [...$this->provenance, ...$other->provenance]);
    }

    private function assertGeometry(string $type, array $geometry): void
    {
        $keys = match ($type) {
            'room' => ['polygon'], 'wall' => ['start', 'end', 'thickness', 'height'],
            'opening' => ['wall_key', 'opening_type', 'offset', 'width', 'height'],
            'engineering_element' => ['engineering_type', 'location', 'room_key'],
        };
        if (array_keys($geometry) !== $keys) {
            throw new InvalidArgumentException('Geometry shape is invalid.');
        }
        if ($type === 'room') {
            if (! is_array($geometry['polygon']) || count($geometry['polygon']) < 3 || count($geometry['polygon']) > 64) {
                throw new InvalidArgumentException('Room geometry is invalid.');
            }
            foreach ($geometry['polygon'] as $point) {
                $this->assertPoint($point);
            }

            return;
        }
        if ($type === 'wall') {
            $this->assertPoint($geometry['start']);
            $this->assertPoint($geometry['end']);
            if ($geometry['start'] === $geometry['end']) {
                throw new InvalidArgumentException('Wall geometry is invalid.');
            }
            $this->assertMeasurement($geometry['thickness']);
            $this->assertMeasurement($geometry['height']);

            return;
        }
        if ($type === 'opening') {
            if (! is_string($geometry['wall_key']) || $geometry['wall_key'] === '' || ! in_array($geometry['opening_type'], ['door', 'window', 'gate'], true)) {
                throw new InvalidArgumentException('Opening geometry is invalid.');
            }
            $this->assertMeasurement($geometry['offset'], true);
            $this->assertMeasurement($geometry['width']);
            $this->assertMeasurement($geometry['height']);

            return;
        }
        if (! in_array($geometry['engineering_type'], ['outlet', 'switch', 'light', 'water_point', 'sewer_point', 'heating_point', 'ventilation_point', 'route'], true)
            || ($geometry['room_key'] !== null && (! is_string($geometry['room_key']) || $geometry['room_key'] === ''))) {
            throw new InvalidArgumentException('Engineering geometry is invalid.');
        }
        $this->assertPoint($geometry['location']);
    }

    private function assertPoint(mixed $point): void
    {
        if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1]) || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1])) {
            throw new InvalidArgumentException('Geometry point is invalid.');
        }
    }

    private function assertMeasurement(mixed $value, bool $allowZero = false): void
    {
        if ($value === null) {
            return;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || ($allowZero ? $value < 0 : $value <= 0)) {
            throw new InvalidArgumentException('Geometry measurement is invalid.');
        }
    }
}
