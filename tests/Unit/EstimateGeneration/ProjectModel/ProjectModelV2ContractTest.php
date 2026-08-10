<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelV2ContractTest extends TestCase
{
    #[Test]
    public function canonical_entities_cover_estimate_relevant_project_objects(): void
    {
        foreach (['room', 'wall', 'opening', 'dimension', 'material', 'equipment', 'quantity'] as $type) {
            $entity = new Entity(
                id: 'entity:'.$type,
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                sourceVersion: $this->sourceVersion('a'),
                type: $type,
                stableKey: $type.':stable-1',
                attributes: ['sheet_role' => 'plan'],
            );

            self::assertSame($type, $entity->type);
        }
    }

    #[Test]
    public function fact_keeps_value_origin_status_and_evidence_as_separate_contracts(): void
    {
        $fact = $this->fact(
            id: 'fact:room-area',
            origin: 'document',
            status: 'confirmed',
            evidenceIds: ['evidence:page-1-room-101'],
        );

        self::assertSame(18.4, $fact->value);
        self::assertSame('m2', $fact->unit);
        self::assertSame('area', $fact->type);
        self::assertSame(0.96, $fact->confidence);
        self::assertSame('document', $fact->origin);
        self::assertSame('confirmed', $fact->status);
        self::assertSame(['evidence:page-1-room-101'], $fact->evidenceIds);
    }

    #[Test]
    public function fact_versions_form_an_explicit_non_self_referencing_chain(): void
    {
        $fact = new Fact(
            id: 'fact:room-area:v2',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            entityId: 'entity:room-1',
            type: 'area',
            value: 18.6,
            unit: 'm2',
            confidence: 0.98,
            origin: 'document',
            status: 'confirmed',
            evidenceIds: ['evidence:page-2-room-101'],
            version: 2,
            supersedesFactId: 'fact:room-area:v1',
        );

        self::assertSame(2, $fact->version);
        self::assertSame('fact:room-area:v1', $fact->supersedesFactId);
    }

    #[Test]
    public function unresolved_and_ai_recommendations_cannot_masquerade_as_confirmed_document_facts(): void
    {
        foreach ([
            ['unresolved', 'confirmed'],
            ['ai_technology_recommendation', 'confirmed'],
        ] as [$origin, $status]) {
            try {
                $this->fact('fact:invalid-'.$origin, $origin, $status, ['evidence:1']);
                self::fail($origin.' was accepted as a confirmed fact.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertSame('unresolved', $this->fact(
            'fact:unresolved',
            'unresolved',
            'unresolved',
            [],
        )->status);
    }

    #[Test]
    public function evidence_is_an_immutable_exact_source_locator(): void
    {
        $evidence = new Evidence(
            id: 'evidence:page-4-region-2',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('b'),
            sourceArtifactId: 'artifact:document-44',
            sourceType: 'pdf',
            page: 4,
            region: ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            nativeReference: 'pdf:text-span:17',
        );

        self::assertSame('artifact:document-44', $evidence->sourceArtifactId);
        self::assertSame(4, $evidence->page);
        self::assertSame('pdf:text-span:17', $evidence->nativeReference);

        $this->expectException(InvalidArgumentException::class);
        new Evidence('evidence:invalid', 1, 2, 3, $this->sourceVersion('b'), 'artifact:document-44', 'pdf');
    }

    #[Test]
    public function conflict_preserves_every_incompatible_fact_and_its_evidence_without_priority_hiding(): void
    {
        $document = $this->fact('fact:document', 'document', 'confirmed', ['evidence:document'], 18.4);
        $inference = $this->fact('fact:inference', 'ai_inference', 'candidate', ['evidence:inference'], 19.1);

        $conflict = Conflict::between(
            id: 'conflict:room-area',
            facts: [$document, $inference],
            reason: 'incompatible_values',
        );

        self::assertSame(['fact:document', 'fact:inference'], array_map(
            static fn (Fact $fact): string => $fact->id,
            $conflict->facts,
        ));
        self::assertSame(['evidence:document', 'evidence:inference'], $conflict->evidenceIds);
        self::assertSame('unresolved', $conflict->status);
    }

    #[Test]
    public function decision_has_actor_reason_and_version_and_ai_recommendation_is_not_a_user_decision(): void
    {
        $decision = new Decision(
            id: 'decision:room-area',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            targetType: 'conflict',
            targetId: 'conflict:room-area',
            selectedFactId: 'fact:document',
            actorType: 'user',
            actorId: '42',
            reason: 'Проверено по листу АР-4',
            version: 2,
        );

        self::assertSame('user', $decision->actorType);
        self::assertSame(2, $decision->version);

        $systemDecision = new Decision(
            'decision:system', 1, 2, 3, $this->sourceVersion('a'), 'conflict', 'conflict:1',
            'fact:document', 'system', 'targeted-conflict-resolver:v1', 'Evidence-preserving arbitration', 1,
        );
        self::assertSame('targeted-conflict-resolver:v1', $systemDecision->actorId);

        $this->expectException(InvalidArgumentException::class);
        new Decision(
            'decision:ai', 1, 2, 3, $this->sourceVersion('a'), 'conflict', 'conflict:1',
            'fact:recommendation', 'ai', 'model', 'Автоматический выбор', 1,
        );
    }

    #[Test]
    public function derived_quantity_keeps_formula_operands_units_rounding_and_evidence_lineage(): void
    {
        $quantity = new DerivedQuantity(
            id: 'quantity:wall-net-area',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            entityId: 'entity:wall-1',
            formula: '(wall_length * wall_height) - openings_area',
            operands: [
                ['fact_id' => 'fact:length', 'value' => 5.0, 'unit' => 'm', 'evidence_ids' => ['evidence:length']],
                ['fact_id' => 'fact:height', 'value' => 3.0, 'unit' => 'm', 'evidence_ids' => ['evidence:height']],
                ['fact_id' => 'fact:openings', 'value' => 2.4, 'unit' => 'm2', 'evidence_ids' => ['evidence:opening']],
            ],
            value: 12.6,
            unit: 'm2',
            roundingMode: 'half_up',
            roundingScale: 2,
            evidenceIds: ['evidence:length', 'evidence:height', 'evidence:opening'],
            status: 'confirmed',
        );

        self::assertSame(12.6, $quantity->value);
        self::assertSame('m2', $quantity->unit);
        self::assertSame(3, count($quantity->operands));
        self::assertSame('half_up', $quantity->roundingMode);
    }

    #[Test]
    public function records_reject_cross_scope_composition(): void
    {
        $left = $this->fact('fact:left', 'document', 'confirmed', ['evidence:left']);
        $right = new Fact(
            id: 'fact:right',
            organizationId: 99,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            entityId: 'entity:room-1',
            type: 'area',
            value: 19.1,
            unit: 'm2',
            confidence: 0.9,
            origin: 'document',
            status: 'confirmed',
            evidenceIds: ['evidence:right'],
        );

        $this->expectException(InvalidArgumentException::class);
        Conflict::between('conflict:cross-scope', [$left, $right], 'incompatible_values');
    }

    private function fact(
        string $id,
        string $origin,
        string $status,
        array $evidenceIds,
        float $value = 18.4,
    ): Fact {
        return new Fact(
            id: $id,
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            entityId: 'entity:room-1',
            type: 'area',
            value: $value,
            unit: 'm2',
            confidence: 0.96,
            origin: $origin,
            status: $status,
            evidenceIds: $evidenceIds,
        );
    }

    private function sourceVersion(string $character): string
    {
        return 'sha256:'.str_repeat($character, 64);
    }
}
