<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceParent;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRelation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvidenceInvalidationTest extends TestCase
{
    #[Test]
    public function invalidates_old_source_and_all_descendants_once_without_deleting_history(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $invalidator = new EvidenceInvalidator($repository, chunkSize: 17);
        $old = $recorder->record($this->data('sha256:old', 'document:44'));
        $new = $recorder->record($this->data('sha256:new', 'document:44'));
        $left = $recorder->record($this->data('v1', 'left', EvidenceType::Extracted), [new EvidenceParent($old->id, EvidenceRelation::DerivedFrom)]);
        $right = $recorder->record($this->data('v1', 'right', EvidenceType::Extracted), [new EvidenceParent($old->id, EvidenceRelation::DerivedFrom)]);
        $diamond = $recorder->record($this->data('v1', 'diamond', EvidenceType::Measured), [
            new EvidenceParent($left->id, EvidenceRelation::DerivedFrom),
            new EvidenceParent($right->id, EvidenceRelation::Supports),
        ]);
        $previous = $diamond;
        for ($index = 0; $index < 1000; $index++) {
            $previous = $recorder->record($this->data('v1', 'chain:'.$index, EvidenceType::Inferred), [
                new EvidenceParent($previous->id, EvidenceRelation::DerivedFrom),
            ]);
        }

        $count = $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', 'sha256:old', 'source_replaced');

        self::assertSame(1004, $count);
        self::assertSame(0, $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', 'sha256:old', 'source_replaced'));
        self::assertCount(1005, $repository->nodes());
        self::assertNull($repository->node(1, 10, 100, $new->id)?->invalidatedAt);
        self::assertNotNull($repository->node(1, 10, 100, $previous->id)?->invalidatedAt);
    }

    #[Test]
    public function invalidation_is_tenant_session_and_source_version_scoped(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $invalidator = new EvidenceInvalidator($repository);
        $target = $recorder->record($this->data('old', 'document:44'));
        $otherVersion = $recorder->record($this->data('new', 'document:44'));
        $otherTenant = $recorder->record($this->data('old', 'document:44', organizationId: 2));

        self::assertSame(1, $invalidator->invalidateSource(1, 10, 100, EvidenceSourceType::Document, 'document:44', 'old', 'source_replaced'));
        self::assertNotNull($repository->node(1, 10, 100, $target->id)?->invalidatedAt);
        self::assertNull($repository->node(1, 10, 100, $otherVersion->id)?->invalidatedAt);
        self::assertNull($repository->node(2, 10, 100, $otherTenant->id)?->invalidatedAt);
    }

    #[Test]
    public function traversal_reaches_active_descendants_through_an_already_invalidated_node(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $root = $recorder->record($this->data('old', 'document:44'));
        $middle = $recorder->record($this->data('v1', 'middle', EvidenceType::Extracted), [
            new EvidenceParent($root->id, EvidenceRelation::DerivedFrom),
        ]);
        $leaf = $recorder->record($this->data('v1', 'leaf', EvidenceType::Measured), [
            new EvidenceParent($middle->id, EvidenceRelation::DerivedFrom),
        ]);
        $repository->invalidate(1, 10, 100, [$middle->id], 'partial_previous_attempt');

        self::assertSame(2, (new EvidenceInvalidator($repository))->invalidateSource(
            1, 10, 100, EvidenceSourceType::Document, 'document:44', 'old', 'source_replaced',
        ));
        self::assertNotNull($repository->node(1, 10, 100, $leaf->id)?->invalidatedAt);
    }

    private function data(
        string $sourceVersion,
        string $sourceRef,
        EvidenceType $type = EvidenceType::SourceFact,
        int $organizationId = 1,
    ): EvidenceData {
        return new EvidenceData(
            organizationId: $organizationId,
            projectId: 10,
            sessionId: 100,
            type: $type,
            sourceType: EvidenceSourceType::Document,
            sourceRef: $sourceRef,
            sourceVersion: $sourceVersion,
            locator: ['page' => 1],
            value: ['kind' => 'wall'],
            confidence: 0.9,
            producerName: 'test',
            producerVersion: '1',
        );
    }
}
