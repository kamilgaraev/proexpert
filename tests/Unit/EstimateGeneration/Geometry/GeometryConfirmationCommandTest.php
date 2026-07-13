<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\GeometryConfirmationParityCases;

final class GeometryConfirmationCommandTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(GeometryConfirmationParityCases::class, 'cases')]
    public function geometry_confirmation_scale_numbers_have_strict_php_types(mixed $realValue, array $indexes, bool $valid): void
    {
        $payload = GeometryConfirmationParityCases::payload($realValue, $indexes);
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $confirmation = GeometryConfirmationData::fromArray($payload);

        self::assertSame($realValue, $confirmation->scaleEvidence[0]['real_world_value']);
    }

    public function test_source_confirmation_rejects_spoofed_audit_and_mixed_mutation_modes(): void
    {
        $semantic = ['schema_version' => 1, 'source_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'geometry_payload_sha256' => str_repeat('b', 64),
            'scale_evidence' => [['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => [0, 1], 'real_world_value' => 1, 'unit' => 'm']],
            'elements' => [['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['W1']]]];
        foreach ([
            [...$semantic, 'reviewer_ref' => 'user:999'],
            [...$semantic, 'confirmed_at' => '2020-01-01T00:00:00Z'],
            [...$semantic, 'confirmation_source' => 'maintainer'],
        ] as $spoofed) {
            try {
                new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('c', 64),
                    'sha256:'.str_repeat('d', 64), null, [], $spoofed);
                self::fail('Client audit metadata must be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('c', 64),
            'sha256:'.str_repeat('d', 64), ['pixel_start' => [0, 0], 'pixel_end' => [1, 0], 'meters' => 1], [], $semantic);
    }

    #[Test]
    public function it_rejects_arbitrary_json_pointer_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), null, [
            ['op' => 'replace', 'path' => '/metrics/room_count', 'value' => 99],
        ]);
    }

    #[Test]
    public function it_accepts_only_bounded_typed_geometry_operations(): void
    {
        $command = new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), null, [
            ['op' => 'replace', 'path' => '/floors/floor-1/rooms/room-1/name', 'value' => 'Кухня'],
        ]);

        self::assertSame('room-1', $command->operations[0]['element_key']);
    }

    #[Test]
    #[DataProvider('invalidOperations')]
    public function it_rejects_malformed_or_unsafe_operations(array $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), null, [$operation]);
    }

    public static function invalidOperations(): iterable
    {
        yield 'escaped pointer' => [['op' => 'replace', 'path' => '/floors/floor-1/rooms/room~1/name', 'value' => 'x']];
        yield 'cross collection field' => [['op' => 'replace', 'path' => '/floors/floor-1/openings/opening-1/material', 'value' => 'x']];
        yield 'nonfinite coordinate' => [['op' => 'replace', 'path' => '/floors/floor-1/walls/wall-1/start', 'value' => [INF, 1]]];
        yield 'degenerate polygon' => [['op' => 'replace', 'path' => '/floors/floor-1/rooms/room-1/polygon', 'value' => [[0, 0], [1, 1]]]];
        yield 'unknown operation' => [['op' => 'add', 'path' => '/floors/floor-1/rooms/room-1/name', 'value' => 'x']];
    }

    #[Test]
    public function regeneration_intent_has_stable_deduplication_key(): void
    {
        $first = new \App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntent(1, 2, 3, 6, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), 'sha256:'.str_repeat('c', 64), 'attempt-1');
        $retry = new \App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntent(1, 2, 3, 6, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), 'sha256:'.str_repeat('c', 64), 'attempt-2');

        self::assertSame($first->idempotencyKey, $retry->idempotencyKey);
    }

    #[Test]
    public function aggregate_payload_limit_is_enforced_before_storage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GeometryConfirmationCommand(1, 2, 3, 4, 5, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), null, [
            ['op' => 'replace', 'path' => '/floors/floor-1/rooms/room-1/name', 'value' => str_repeat('x', 262145)],
        ]);
    }
}
