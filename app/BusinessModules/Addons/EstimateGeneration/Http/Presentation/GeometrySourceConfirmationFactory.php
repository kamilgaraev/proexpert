<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use InvalidArgumentException;

final readonly class GeometrySourceConfirmationFactory
{
    /** @return array<string, mixed>|null */
    public function make(VectorGeometryData $vector): ?array
    {
        if ($vector->unitStatus !== 'confirmed' || $vector->sourceUnit === null) {
            return null;
        }

        $rooms = [];
        $walls = [];
        $scaleEvidence = null;
        foreach ($vector->entities as $entity) {
            $handle = $entity['handle'] ?? null;
            if (! is_string($handle)) {
                return null;
            }
            if (($entity['type'] ?? null) === 'lwpolyline' || ($entity['type'] ?? null) === 'polyline') {
                if (($entity['closed'] ?? null) === true && is_array($entity['points'] ?? null) && count($entity['points']) >= 3) {
                    $rooms[] = [
                        'key' => $this->key('room', $handle),
                        'type' => 'room',
                        'boundary_handle' => $handle,
                    ];
                }

                continue;
            }
            $semantic = $entity['semantic'] ?? null;
            $segmentLength = $this->segmentLength($entity['points'] ?? null);
            if (($entity['type'] ?? null) !== 'line' || (is_array($semantic) && ($semantic['kind'] ?? null) === 'opening')
                || $segmentLength === null) {
                continue;
            }
            $walls[] = [
                'key' => $this->key('wall', $handle),
                'type' => 'wall',
                'segment_handles' => [$handle],
            ];
            if ($scaleEvidence === null) {
                $scaleEvidence = [
                    'role' => 'measured_segment',
                    'entity_handle' => $handle,
                    'point_indexes' => [0, 1],
                    'real_world_value' => $segmentLength,
                    'unit' => $vector->sourceUnit,
                ];
            }
        }
        if ($rooms === [] || $walls === [] || $scaleEvidence === null) {
            return null;
        }
        $payload = [
            'schema_version' => 1,
            'source_fingerprint' => $vector->sourceFingerprint,
            'geometry_payload_sha256' => $vector->payloadSha256(),
            'scale_evidence' => [$scaleEvidence],
            'elements' => [...$rooms, ...$walls],
        ];

        try {
            GeometryConfirmationData::fromArray($payload);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $payload;
    }

    private function segmentLength(mixed $points): ?float
    {
        if (! is_array($points) || count($points) !== 2 || ! is_array($points[0]) || ! is_array($points[1])
            || ! is_numeric($points[0][0] ?? null) || ! is_numeric($points[0][1] ?? null)
            || ! is_numeric($points[1][0] ?? null) || ! is_numeric($points[1][1] ?? null)) {
            return null;
        }

        $length = hypot(
            (float) $points[1][0] - (float) $points[0][0],
            (float) $points[1][1] - (float) $points[0][1],
        );

        return $length > 0.0 ? $length : null;
    }

    private function key(string $type, string $handle): string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($handle)), '-');
        $normalized = $normalized === '' ? 'element' : $normalized;

        return $type.'-'.substr($normalized, 0, 96).'-'.substr(hash('sha256', $handle), 0, 12);
    }
}
