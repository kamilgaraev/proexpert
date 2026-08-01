<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ConfirmedProjectModelProjector;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelAssertion;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntity;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceBinding;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelConflict;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelMerger;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelResolvedValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelMergerTest extends TestCase
{
    #[Test]
    public function manual_correction_has_priority_over_all_evidenced_sources(): void
    {
        $entity = $this->entity('room-1', 'room');
        $assertion = $this->assertion('assertion:room-1:area:cad', 'room-1', 'area', ['value' => 18.0, 'unit' => 'm2', 'source' => 'cad']);
        $manualCorrection = $this->correction('correction:room-1:area:manual', $assertion->stableKey, ['value' => 19.5, 'unit' => 'm2']);

        $merged = (new ProjectModelMerger)->merge(
            [$entity],
            [$assertion, $this->assertion('assertion:room-1:area:ai', 'room-1', 'area', ['value' => 17.0, 'unit' => 'm2', 'source' => 'ai_candidate'])],
            [$manualCorrection],
            [$this->binding('room-1')],
        );

        self::assertEquals([new ProjectModelResolvedValue(
            'room-1',
            'area',
            ['unit' => 'm2', 'value' => 19.5],
            'manual_correction',
            'assertion:room-1:area:cad',
            'correction:room-1:area:manual',
        )], $merged->resolved);
        self::assertSame([], $merged->conflicts);
    }

    #[Test]
    public function equally_ranked_area_candidates_are_retained_as_a_conflict_without_a_resolved_value(): void
    {
        $entity = $this->entity('room-1', 'room');

        $merged = (new ProjectModelMerger)->merge(
            [$entity],
            [
                $this->assertion('assertion:room-1:area:cad-a', 'room-1', 'area', ['value' => 18.0, 'unit' => 'm2', 'source' => 'cad']),
                $this->assertion('assertion:room-1:area:cad-b', 'room-1', 'area', ['value' => 21.0, 'unit' => 'm2', 'source' => 'cad']),
            ],
            [],
            [$this->binding('room-1')],
        );

        self::assertSame([], $merged->resolved);
        self::assertEquals([new ProjectModelConflict(
            'room-1',
            'area',
            'area_conflict',
            ['assertion:room-1:area:cad-a', 'assertion:room-1:area:cad-b'],
            [['unit' => 'm2', 'value' => 18.0], ['unit' => 'm2', 'value' => 21.0]],
        )], $merged->conflicts);
    }

    #[Test]
    public function it_uses_authoritative_sources_before_reconciled_geometry_and_ai_candidates(): void
    {
        $dimension = $this->entity('dimension-1', 'dimension');

        $merged = (new ProjectModelMerger)->merge(
            [$dimension],
            [
                $this->assertion('assertion:dimension-1:dimension:ai', 'dimension-1', 'dimension', ['value' => 4.0, 'unit' => 'm', 'source' => 'ai_candidate']),
                $this->assertion('assertion:dimension-1:dimension:geometry', 'dimension-1', 'dimension', ['value' => 4.2, 'unit' => 'm', 'source' => 'reconciled_geometry']),
                $this->assertion('assertion:dimension-1:dimension:explicit', 'dimension-1', 'dimension', ['value' => 4.5, 'unit' => 'm', 'source' => 'explicit_dimension']),
            ],
            [],
            [$this->binding('dimension-1')],
        );

        self::assertSame(4.5, $merged->resolved[0]->value['value']);
        self::assertSame('explicit_dimension', $merged->resolved[0]->source);
        self::assertSame([], $merged->conflicts);
    }

    #[Test]
    public function conflicts_in_room_purpose_and_opening_are_preserved_and_not_projected_as_confirmed_values(): void
    {
        $room = $this->entity('room-1', 'room');
        $opening = $this->entity('opening-1', 'opening');
        $merged = (new ProjectModelMerger)->merge(
            [$room, $opening],
            [
                $this->assertion('assertion:room-1:purpose:a', 'room-1', 'room_purpose', ['value' => 'kitchen', 'source' => 'table']),
                $this->assertion('assertion:room-1:purpose:b', 'room-1', 'room_purpose', ['value' => 'bedroom', 'source' => 'table']),
                $this->assertion('assertion:opening-1:opening:a', 'opening-1', 'opening', ['type' => 'door', 'width_m' => 0.9, 'height_m' => 2.1, 'source' => 'cad']),
                $this->assertion('assertion:opening-1:opening:b', 'opening-1', 'opening', ['type' => 'window', 'width_m' => 1.2, 'height_m' => 1.4, 'source' => 'cad']),
            ],
            [],
            [$this->binding('room-1'), $this->binding('opening-1')],
        );

        self::assertSame(['opening_conflict', 'room_purpose_conflict'], array_map(static fn (ProjectModelConflict $conflict): string => $conflict->code, $merged->conflicts));
        self::assertSame([], (new ConfirmedProjectModelProjector)->project($merged)->values);
    }

    #[Test]
    public function projection_excludes_unevidenced_and_ai_only_candidates(): void
    {
        $entity = $this->entity('room-1', 'room');
        $merged = (new ProjectModelMerger)->merge(
            [$entity],
            [$this->assertion('assertion:room-1:area:ai', 'room-1', 'area', ['value' => 18.0, 'unit' => 'm2', 'source' => 'ai_candidate'])],
            [],
            [],
        );

        self::assertSame([], $merged->resolved);
        self::assertEquals([new ProjectModelConflict(
            'room-1',
            'area',
            'area_unconfirmed',
            ['assertion:room-1:area:ai'],
        )], $merged->unconfirmed);
        self::assertSame([], (new ConfirmedProjectModelProjector)->project($merged)->values);
    }

    private function entity(string $stableKey, string $kind): ProjectModelEntity
    {
        $payload = match ($kind) {
            'room' => ['kind' => 'room', 'key' => $stableKey, 'area_m2' => 1.0],
            'opening' => ['kind' => 'opening', 'key' => $stableKey, 'wall_key' => 'wall-1', 'type' => 'door', 'width_m' => 0.9, 'height_m' => 2.1],
            'dimension' => ['kind' => 'dimension', 'key' => $stableKey, 'value' => 1.0, 'unit' => 'm'],
        };

        return new ProjectModelEntity(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $kind, $payload);
    }

    private function assertion(string $stableKey, string $entityStableKey, string $type, array $payload): ProjectModelAssertion
    {
        return new ProjectModelAssertion(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $entityStableKey, $type, $payload, 0.95);
    }

    private function correction(string $stableKey, string $assertionStableKey, array $payload): ProjectModelCorrection
    {
        return new ProjectModelCorrection(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $assertionStableKey, 'manual', $payload, 'Проверено специалистом', 42);
    }

    private function binding(string $entityStableKey): ProjectModelEvidenceBinding
    {
        return new ProjectModelEvidenceBinding(10, 1, 2, 3, $this->sourceVersion(), $entityStableKey, 17, 'sha256:'.str_repeat('c', 64), 0);
    }

    private function sourceVersion(): string
    {
        return 'sha256:'.str_repeat('b', 64);
    }
}
