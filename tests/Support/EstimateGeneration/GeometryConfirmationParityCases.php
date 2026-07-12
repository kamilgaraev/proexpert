<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

final class GeometryConfirmationParityCases
{
    public static function cases(): iterable
    {
        yield 'integer value' => [1, [0, 1], true];
        yield 'float value' => [1.5, [0, 1], true];
        yield 'numeric string' => ['1', [0, 1], false];
        yield 'boolean' => [true, [0, 1], false];
        yield 'infinite' => [INF, [0, 1], false];
        yield 'nan' => [NAN, [0, 1], false];
        yield 'decimal index' => [1, [0.0, 1], false];
        yield 'string index' => [1, ['0', 1], false];
    }

    public static function payload(mixed $realValue, array $indexes): array
    {
        return ['schema_version' => 1, 'source_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'geometry_payload_sha256' => str_repeat('b', 64),
            'scale_evidence' => [['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => $indexes, 'real_world_value' => $realValue, 'unit' => 'm']],
            'elements' => [['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['W1']]]];
    }
}
