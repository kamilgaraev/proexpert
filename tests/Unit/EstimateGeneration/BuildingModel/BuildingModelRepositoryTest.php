<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelContentCollision;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\InMemoryBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildingModelRepositoryTest extends TestCase
{
    #[Test]
    public function same_content_is_idempotent_and_different_content_collides(): void
    {
        [$repository, $context] = $this->repositoryWithEvidence();
        $model = $this->model(2.8);

        $first = $repository->store($context, $model);
        $second = $repository->store($context, $model);

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->id, $second->id);

        $this->expectException(BuildingModelContentCollision::class);
        $repository->store($context, $this->model(3.0));
    }

    #[Test]
    public function evidence_must_exist_be_active_and_match_exact_tenant(): void
    {
        [$repository, $context] = $this->repositoryWithEvidence(organizationId: 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence');
        $repository->store(new BuildingModelOperationContext(1, 2, 3, $context->inputVersion), $this->model(2.8));
    }

    #[Test]
    public function model_is_current_only_while_every_linked_evidence_is_active(): void
    {
        [$repository, $context, $evidence] = $this->repositoryWithEvidence();
        $stored = $repository->store($context, $this->model(2.8));

        self::assertSame($stored->id, $repository->current($context)?->id);
        $evidence->invalidate(1, 2, 3, [1], 'source_replaced');
        self::assertNull($repository->current($context));
    }

    private function repositoryWithEvidence(int $organizationId = 1): array
    {
        $evidence = new InMemoryEvidenceRepository;
        $evidence->insertOrGet(new EvidenceData(
            $organizationId,
            2,
            3,
            EvidenceType::Extracted,
            EvidenceSourceType::Document,
            'document:1',
            'sha256:'.str_repeat('a', 64),
            ['document_id' => 1],
            ['field_key' => 'floor_height', 'field_value' => 2.8, 'unit' => 'm'],
            1,
            'contract',
            'contract:abcdef',
        ));
        $context = new BuildingModelOperationContext($organizationId, 2, 3, 'sha256:'.str_repeat('b', 64));

        return [new BuildingModelRepository(new InMemoryBuildingModelStore, $evidence), $context, $evidence];
    }

    private function model(float $height): NormalizedBuildingModelData
    {
        return new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
            new FloorData('floor-1', 0, $height, [], [], [], [], [1], 1, 'confirmed'),
        ], [], 'building-model:v1');
    }
}
