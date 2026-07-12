<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use InvalidArgumentException;

final readonly class GeometryConfirmationCommand
{
    /** @var list<array{op: string, path: string, floor_key: string, collection: string, element_key: string, field: string, value: mixed}> */
    public array $operations;

    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $actorId,
        public int $expectedStateVersion,
        public string $expectedModelVersion,
        public string $expectedInputVersion,
        public ?array $scale,
        array $operations,
    ) {
        if (! preg_match('/^sha256:[a-f0-9]{64}$/', $expectedModelVersion)
            || ! preg_match('/^sha256:[a-f0-9]{64}$/', $expectedInputVersion)) {
            throw new InvalidArgumentException('Geometry version is invalid.');
        }
        if ($scale === null && $operations === []) {
            throw new InvalidArgumentException('Geometry confirmation must contain a change.');
        }
        if ($scale !== null) {
            if (array_keys($scale) !== ['pixel_start', 'pixel_end', 'meters']
                || ! $this->point($scale['pixel_start']) || ! $this->point($scale['pixel_end'])
                || ! is_numeric($scale['meters']) || ! is_finite((float) $scale['meters']) || (float) $scale['meters'] <= 0
                || hypot((float) $scale['pixel_end'][0] - (float) $scale['pixel_start'][0], (float) $scale['pixel_end'][1] - (float) $scale['pixel_start'][1]) <= 0.0) {
                throw new InvalidArgumentException('Scale control dimension is invalid.');
            }
        }
        if (count($operations) > 100) {
            throw new InvalidArgumentException('Too many geometry operations.');
        }
        $normalized = [];
        foreach ($operations as $operation) {
            if (! is_array($operation) || array_keys($operation) !== ['op', 'path', 'value']
                || $operation['op'] !== 'replace' || ! is_string($operation['path'])
                || str_contains($operation['path'], '~') || strlen($operation['path']) > 256) {
                throw new InvalidArgumentException('Geometry operation is invalid.');
            }
            if (! preg_match('#^/floors/([a-z][a-z0-9_-]{0,127})/(rooms|walls|openings)/([a-z][a-z0-9_-]{0,127})/(name|polygon|start|end|type|material|offset_m|width_m|height_m)$#', $operation['path'], $matches)) {
                throw new InvalidArgumentException('Geometry operation path is not allowed.');
            }
            $allowed = [
                'rooms' => ['name', 'polygon'],
                'walls' => ['start', 'end', 'type', 'material', 'height_m'],
                'openings' => ['type', 'offset_m', 'width_m', 'height_m'],
            ];
            if (! in_array($matches[4], $allowed[$matches[2]], true)) {
                throw new InvalidArgumentException('Geometry field is not allowed for this element.');
            }
            $this->assertValue($matches[4], $operation['value']);
            $normalized[] = $operation + [
                'floor_key' => $matches[1], 'collection' => $matches[2],
                'element_key' => $matches[3], 'field' => $matches[4],
            ];
        }
        $this->operations = $normalized;
    }

    private function point(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) === 2
            && is_numeric($value[0]) && is_numeric($value[1])
            && is_finite((float) $value[0]) && is_finite((float) $value[1]);
    }

    private function assertValue(string $field, mixed $value): void
    {
        if (in_array($field, ['name', 'type', 'material'], true)) {
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 160) {
                throw new InvalidArgumentException('Geometry text value is invalid.');
            }

            return;
        }
        if ($field === 'polygon') {
            if (! is_array($value) || ! array_is_list($value) || count($value) < 3 || count($value) > 500) {
                throw new InvalidArgumentException('Geometry polygon is invalid.');
            }
            foreach ($value as $point) {
                if (! $this->point($point)) {
                    throw new InvalidArgumentException('Geometry polygon point is invalid.');
                }
            }

            return;
        }
        if (in_array($field, ['start', 'end'], true) ? ! $this->point($value)
            : (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0)) {
            throw new InvalidArgumentException('Geometry numeric value is invalid.');
        }
    }
}
