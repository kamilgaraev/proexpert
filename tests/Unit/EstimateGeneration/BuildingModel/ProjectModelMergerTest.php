<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ConfirmedProjectModelProjector;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelAssertion;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelAssertionList;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntity;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntityList;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceBinding;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceBindingList;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelMerger;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelResolvedValue;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelMergerTest extends TestCase
{
    #[Test]
    public function manual_correction_has_priority_as_an_immutable_audited_confirmation(): void
    {
        $entity = $this->entity('room-1', 'room');
        $assertion = $this->assertion('assertion:room-1:area:cad', 'room-1', 'area', ['value' => 18.0, 'unit' => 'm2', 'source' => 'cad']);
        $correction = $this->correction('correction:room-1:area:manual', $assertion->stableKey, ['value' => 19.5, 'unit' => 'm2']);

        $merged = $this->merge($entity, [$assertion], [$correction], [
            $this->binding($entity->stableKey, $assertion, null, 'cad', ['value' => 18, 'unit' => 'm2']),
        ]);

        $value = $this->first($merged->resolved);
        self::assertSame(19.5, $value->value['value']);
        self::assertSame('manual_correction', $value->source);
        self::assertSame(ProjectModelValueFingerprint::for($correction->payload), $correction->valueFingerprint());
    }

    #[Test]
    public function evidence_for_another_assertion_of_the_same_entity_does_not_confirm_a_candidate(): void
    {
        $entity = $this->entity('room-1', 'room');
        $evidenced = $this->assertion('assertion:room-1:area:a', 'room-1', 'area', ['value' => 18, 'unit' => 'm2', 'source' => 'cad']);
        $unevidenced = $this->assertion('assertion:room-1:area:b', 'room-1', 'area', ['value' => 21, 'unit' => 'm2', 'source' => 'cad']);

        $merged = $this->merge($entity, [$evidenced, $unevidenced], [], [
            $this->binding($entity->stableKey, $evidenced, null, 'cad', ['value' => 18.0, 'unit' => 'm2']),
        ]);

        self::assertCount(1, $merged->resolved);
        self::assertSame(18, $this->first($merged->resolved)->value['value']);
        self::assertCount(0, $merged->conflicts);
    }

    #[Test]
    public function semantic_numeric_normalization_treats_integer_and_fractional_notation_as_equal(): void
    {
        $entity = $this->entity('room-1', 'room');
        $first = $this->assertion('assertion:room-1:area:a', 'room-1', 'area', ['value' => 18, 'unit' => 'm2', 'source' => 'cad']);
        $second = $this->assertion('assertion:room-1:area:b', 'room-1', 'area', ['value' => 18.0, 'unit' => 'm2', 'source' => 'cad']);

        self::assertSame(ProjectModelValueFingerprint::for(['value' => 18, 'unit' => 'm2']), ProjectModelValueFingerprint::for(['value' => 18.0, 'unit' => 'm2']));
        $merged = $this->merge($entity, [$first, $second], [], [
            $this->binding($entity->stableKey, $first, null, 'cad', ['value' => 18.0, 'unit' => 'm2']),
            $this->binding($entity->stableKey, $second, null, 'cad', ['value' => 18, 'unit' => 'm2']),
        ]);

        self::assertCount(1, $merged->resolved);
        self::assertCount(0, $merged->conflicts);
    }

    #[Test]
    public function source_priority_never_hides_incompatible_evidenced_facts(): void
    {
        $entity = $this->entity('room-1', 'room');
        $cad = $this->assertion('assertion:room-1:area:cad', 'room-1', 'area', ['value' => 18, 'unit' => 'm2', 'source' => 'cad']);
        $reconciled = new ProjectModelCorrection(
            10,
            1,
            2,
            3,
            $this->sourceVersion(),
            'correction:room-1:area:reconciled',
            $cad->stableKey,
            'source_reconciliation',
            ['value' => 21, 'unit' => 'm2'],
            'Получено из связанного листа',
            42,
        );

        $merged = $this->merge($entity, [$cad], [$reconciled], [
            $this->binding($entity->stableKey, $cad, null, 'cad', ['value' => 18, 'unit' => 'm2']),
            $this->binding($entity->stableKey, $cad, $reconciled, 'reconciled_geometry', ['value' => 21, 'unit' => 'm2']),
        ]);

        self::assertCount(0, $merged->resolved);
        self::assertCount(1, $merged->conflicts);
        self::assertSame(
            ['assertion:room-1:area:cad', 'correction:room-1:area:reconciled'],
            iterator_to_array($merged->conflicts, false)[0]->candidateStableKeys,
        );
    }

    #[Test]
    public function resolved_values_cannot_be_fabricated_and_projection_requires_canonical_proof(): void
    {
        self::assertFalse((new \ReflectionMethod(ProjectModelResolvedValue::class, '__construct'))->isPublic());
        $entity = $this->entity('room-1', 'room');
        $assertion = $this->assertion('assertion:room-1:area:ai', 'room-1', 'area', ['value' => 18, 'unit' => 'm2', 'source' => 'ai_candidate']);

        $merged = $this->merge($entity, [$assertion], [], []);
        self::assertCount(0, $merged->resolved);
        self::assertCount(0, (new ConfirmedProjectModelProjector)->project($merged)->values);
    }

    /** @param list<ProjectModelAssertion> $assertions @param list<ProjectModelCorrection> $corrections @param list<ProjectModelEvidenceBinding> $bindings */
    private function merge(ProjectModelEntity $entity, array $assertions, array $corrections, array $bindings): \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelMergeResult
    {
        return (new ProjectModelMerger)->merge(
            ProjectModelEntityList::of($entity),
            ProjectModelAssertionList::of(...$assertions),
            $corrections,
            ProjectModelEvidenceBindingList::of(...$bindings),
        );
    }

    private function first(\Traversable $values): ProjectModelResolvedValue
    {
        foreach ($values as $value) {
            return $value;
        }
        self::fail('Expected a resolved value.');
    }

    private function entity(string $stableKey, string $kind): ProjectModelEntity
    {
        return new ProjectModelEntity(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $kind, ['kind' => 'room', 'key' => $stableKey, 'area_m2' => 1.0]);
    }

    /** @param array<string, mixed> $payload */
    private function assertion(string $stableKey, string $entityStableKey, string $type, array $payload): ProjectModelAssertion
    {
        return new ProjectModelAssertion(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $entityStableKey, $type, $payload, 0.95);
    }

    /** @param array<string, mixed> $payload */
    private function correction(string $stableKey, string $assertionStableKey, array $payload): ProjectModelCorrection
    {
        return new ProjectModelCorrection(10, 1, 2, 3, $this->sourceVersion(), $stableKey, $assertionStableKey, 'manual', $payload, 'Проверено специалистом', 42);
    }

    /** @param array<string, mixed> $value */
    private function binding(string $entityStableKey, ProjectModelAssertion $assertion, ?ProjectModelCorrection $correction, string $source, array $value): ProjectModelEvidenceBinding
    {
        return new ProjectModelEvidenceBinding(10, 1, 2, 3, $this->sourceVersion(), $entityStableKey, $assertion->stableKey, $correction?->stableKey, 17, $source, ProjectModelValueFingerprint::for($value), 'sha256:'.str_repeat('c', 64), 0);
    }

    private function sourceVersion(): string
    {
        return 'sha256:'.str_repeat('b', 64);
    }
}
