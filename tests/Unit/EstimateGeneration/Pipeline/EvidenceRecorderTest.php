<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceParent;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRelation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EvidenceRecorderTest extends TestCase
{
    #[Test]
    public function canonical_evidence_is_deduplicated_and_immutable(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $locator = ['page' => 2, 'bbox' => [0.1, 0.2, 0.4, 0.5]];
        $value = ['unit' => 'м2', 'amount' => 12.4];
        $first = $recorder->record($this->data(locator: $locator, value: $value));

        $locator['page'] = 99;
        $value['amount'] = 1;
        $second = $recorder->record($this->data(
            locator: ['bbox' => [0.1, 0.2, 0.4, 0.5], 'page' => 2],
            value: ['amount' => 12.4, 'unit' => 'м2'],
        ));

        self::assertSame($first->id, $second->id);
        self::assertSame(2, $first->locator['page']);
        self::assertSame(12.4, $first->value['amount']);
    }

    #[Test]
    public function every_semantic_field_changes_the_fingerprint(): void
    {
        $base = $this->data();
        $variants = [
            $this->data(confidence: 0.91),
            $this->data(producerVersion: '2'),
            $this->data(value: ['amount' => 12.5, 'unit' => 'м2']),
            $this->data(sourceVersion: 'sha256:b'),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base->fingerprint(), $variant->fingerprint());
        }
    }

    #[Test]
    public function duplicate_insert_and_valid_parent_edge_are_idempotent(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $parent = $recorder->record($this->data());
        $childData = $this->data(type: EvidenceType::Measured, sourceRef: 'page:2');

        $first = $recorder->record($childData, [new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom)]);
        $second = $recorder->record($childData, [new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom)]);

        self::assertSame($first->id, $second->id);
        self::assertCount(2, $repository->nodes());
        self::assertCount(1, $repository->edges());
    }

    #[Test]
    public function rejects_cross_scope_self_cycle_and_invalidated_parent_edges(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $parent = $recorder->record($this->data());

        foreach ([
            $this->data(organizationId: 2, sourceRef: 'other-org'),
            $this->data(projectId: 20, sourceRef: 'other-project'),
            $this->data(sessionId: 200, sourceRef: 'other-session'),
        ] as $data) {
            try {
                $recorder->record($data, [new EvidenceParent($parent->id, EvidenceRelation::Supports)]);
                self::fail('Cross-scope edge was accepted.');
            } catch (RuntimeException $error) {
                self::assertSame('estimate_generation.evidence_parent_scope_invalid', $error->getMessage());
            }
        }

        $child = $recorder->record($this->data(type: EvidenceType::Measured, sourceRef: 'child'), [
            new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
        ]);

        try {
            $recorder->attach($this->data(), $parent->id, [new EvidenceParent($parent->id, EvidenceRelation::Supports)]);
            self::fail('Self-edge was accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('estimate_generation.evidence_self_edge', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $recorder->attach($this->data(), $parent->id, [new EvidenceParent($child->id, EvidenceRelation::DerivedFrom)]);
    }

    #[Test]
    public function invalidated_parent_cannot_receive_a_new_child(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $recorder = new EvidenceRecorder($repository);
        $parent = $recorder->record($this->data());
        $repository->invalidate(1, 10, 100, [$parent->id], 'source_replaced');

        $this->expectExceptionMessage('estimate_generation.evidence_parent_invalidated');
        $recorder->record($this->data(type: EvidenceType::Measured, sourceRef: 'child'), [
            new EvidenceParent($parent->id, EvidenceRelation::DerivedFrom),
        ]);
    }

    #[Test]
    public function rejects_unsafe_or_unbounded_json_and_source_text(): void
    {
        $invalid = [
            ['value' => ['password' => 'secret']],
            ['value' => ['raw_text' => str_repeat('x', 20)]],
            ['value' => ['number' => INF]],
            ['value' => ['nested' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 1]]]]]]]],
            ['value' => ['blob' => str_repeat('x', 16_385)]],
        ];

        foreach ($invalid as $override) {
            try {
                $this->data(...$override);
                self::fail('Unsafe evidence was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function data(
        int $organizationId = 1,
        int $projectId = 10,
        int $sessionId = 100,
        EvidenceType $type = EvidenceType::SourceFact,
        string $sourceRef = 'document:44',
        string $sourceVersion = 'sha256:a',
        array $locator = ['page' => 2],
        array $value = ['amount' => 12.4, 'unit' => 'м2'],
        float $confidence = 0.93,
        string $producerVersion = '1',
    ): EvidenceData {
        return new EvidenceData(
            organizationId: $organizationId,
            projectId: $projectId,
            sessionId: $sessionId,
            type: $type,
            sourceType: EvidenceSourceType::Document,
            sourceRef: $sourceRef,
            sourceVersion: $sourceVersion,
            locator: $locator,
            value: $value,
            confidence: $confidence,
            producerName: 'pdf_geometry',
            producerVersion: $producerVersion,
        );
    }
}
