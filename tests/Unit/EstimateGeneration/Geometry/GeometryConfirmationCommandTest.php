<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryConfirmationCommandTest extends TestCase
{
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
