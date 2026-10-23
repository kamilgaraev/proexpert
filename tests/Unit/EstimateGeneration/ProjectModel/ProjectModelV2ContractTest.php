<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelV2ContractTest extends TestCase
{
    #[Test]
    public function engineering_parameter_namespace_requires_a_definition_despite_confirmed_status_and_a_document_link(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Fact('fact:parameter', 1, 2, 3, $this->sourceVersion('a'), 'equipment:1',
            'engineering_parameter_'.str_repeat('a', 40), '102', 'mm', 1.0, 'document', 'confirmed', ['evidence:1']);
    }

    #[Test]
    public function named_parameters_keep_exact_scalars_and_separate_unknown_input_from_a_document_threshold(): void
    {
        $attributes = $this->engineeringAttributes();
        $input = $this->engineeringFact($attributes);
        self::assertSame('102', $input->value);
        self::assertSame('mm', $input->unit);
        self::assertSame('candidate', $input->status);
        self::assertSame('user_input', $input->origin);
        self::assertSame($attributes['engineering_parameter']['definition_id'], $input->semanticAttributes['engineering_parameter']['definition_id']);
        $unknown = $this->engineeringFact($attributes, null, null, 'unresolved', 'unresolved');
        self::assertNull($unknown->value);
        self::assertSame('unresolved', $unknown->status);
        $threshold = $this->engineeringAttributes(role: 'minimum_length_requirement');
        self::assertSame($attributes['engineering_parameter']['parameter_id'], $threshold['engineering_parameter']['parameter_id']);
        self::assertNotSame($input->type, FactVocabulary::engineeringParameterType($threshold));
        self::assertNotSame($input->type, FactVocabulary::engineeringParameterType($this->engineeringAttributes('Другой размер')));
        self::assertSame(62, strlen($input->type));
        self::assertArrayHasKey('semantic_attributes', json_decode(json_encode($input, JSON_THROW_ON_ERROR), true));
        self::assertArrayNotHasKey('semanticAttributes', json_decode(json_encode($input, JSON_THROW_ON_ERROR), true));
    }

    #[Test]
    public function generic_parameters_reject_malformed_foreign_and_prefix_only_definitions(): void
    {
        $attributes = $this->engineeringAttributes();
        $invalid = [];
        $invalid[] = [...$attributes, 'trusted' => true];
        $invalid[] = ['engineering_parameter' => [...$attributes['engineering_parameter'], 'confirmed' => true]];
        foreach ([['dimension', 'pressure'], ['schema_version', 2], ['parameter_id', str_repeat('b', 64)],
            ['definition_id', substr($attributes['engineering_parameter']['definition_id'], 0, 40).str_repeat('f', 24)]] as [$key, $value]) {
            $row = $attributes;
            $row['engineering_parameter'][$key] = $value;
            $invalid[] = $row;
        }
        $foreign = $attributes;
        $foreign['engineering_parameter']['binding']['entity_id'] = 'equipment:other';
        $invalid[] = $foreign;
        foreach ($invalid as $row) {
            try {
                $this->engineeringFact($row);
                self::fail('Invalid or foreign definition was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([[102.0, 'mm', 'candidate'], ['NaN', 'mm', 'candidate'], ['1e2', 'mm', 'candidate'],
            ['0', 'mm', 'candidate'], ['-1', 'mm', 'candidate'], ['1.00001', 'mm', 'candidate'],
            ['1000000000000.0001', 'mm', 'candidate'], ['102', 'm2', 'candidate'], [null, null, 'confirmed']] as [$value, $unit, $status]) {
            try {
                $this->engineeringFact($attributes, $value, $unit, 'user_input', $status);
                self::fail('Invalid scalar was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new Fact('fact:foreign', 99, 2, 3, $this->sourceVersion('a'), 'equipment:1',
            FactVocabulary::engineeringParameterType($attributes), '102', 'mm', 0, 'user_input', 'candidate', ['evidence:1'],
            semanticAttributes: $attributes);
    }

    #[Test]
    public function generic_native_contract_requires_all_definition_pins_and_rejects_human_origin(): void
    {
        $attributes = $this->engineeringAttributes();
        $definition = $attributes['engineering_parameter'];
        $evidence = new Evidence('evidence:1', 1, 2, 3, $this->sourceVersion('a'), 'document:1', 'document', 1,
            producerName: 'native_numeric_parser', fingerprint: str_repeat('b', 64));
        $proof = ['entity_id' => 'equipment:1', 'source_version' => $this->sourceVersion('a'),
            'parameter' => FactVocabulary::engineeringParameterType($attributes), 'evidence_fingerprint' => str_repeat('b', 64),
            'measurement' => 'm:0.102'];
        $checker = new NativeNumericFactProof;
        self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', status: 'confirmed'), $evidence));
        self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $proof]), $evidence));
        $proof += ['definition_id' => $definition['definition_id'], 'parameter_id' => $definition['parameter_id'],
            'definition_source' => $definition['definition_source']];
        self::assertFalse($checker->matches($this->engineeringFact($attributes, proofs: ['evidence:1' => $proof]), $evidence));
        self::assertTrue($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $proof]), $evidence));
        foreach (['definition_id', 'parameter_id', 'definition_source'] as $key) {
            $incomplete = $proof;
            unset($incomplete[$key]);
            self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $incomplete]), $evidence));
        }
        $changed = $proof;
        $changed['definition_id'] = substr($definition['definition_id'], 0, 40).str_repeat('f', 24);
        self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $changed]), $evidence));
        $foreign = new Evidence('evidence:1', 99, 2, 3, $this->sourceVersion('a'), 'document:1', 'document', 1,
            producerName: 'native_numeric_parser', fingerprint: str_repeat('b', 64));
        self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $proof]), $foreign));
        $malformed = $proof;
        $malformed['definition_source']['evidence_fingerprint'] = "\xB1";
        self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: 'document', proofs: ['evidence:1' => $malformed]), $evidence));
        foreach (['ai_inference', 'user_assumption', 'ai_technology_recommendation'] as $origin) {
            self::assertFalse($checker->matches($this->engineeringFact($attributes, origin: $origin, proofs: ['evidence:1' => $proof]), $evidence));
        }
    }

    #[Test]
    public function threshold_source_version_is_separate_from_the_target_entity_and_never_inferred_from_the_value(): void
    {
        $binding = ['entity_id' => 'equipment:1', 'entity_source_version' => $this->sourceVersion('a'),
            'work_key' => 'generic.install', 'work_binding_hash' => str_repeat('c', 64)];
        $source = ['evidence_id' => 'evidence:1', 'source_version' => $this->sourceVersion('b'), 'evidence_fingerprint' => str_repeat('d', 64)];
        $observed = FactVocabulary::engineeringParameterAttributes(1, 2, 3, $binding, 'Размер B', 'observed_value', $source);
        $minimum = FactVocabulary::engineeringParameterAttributes(1, 2, 3, $binding, 'Размер B', 'minimum_length_requirement', $source);
        $input = $this->engineeringFact($observed);
        $condition = new Fact('fact:condition', 1, 2, 3, $this->sourceVersion('b'), 'quantity:document1',
            FactVocabulary::engineeringParameterType($minimum), '102', 'mm', 0.0, 'document', 'candidate', ['evidence:1'],
            semanticAttributes: $minimum);
        self::assertNotSame($input->sourceVersion, $condition->sourceVersion);
        self::assertNotSame($input->entityId, $condition->entityId);
        self::assertSame($observed['engineering_parameter']['parameter_id'], $minimum['engineering_parameter']['parameter_id']);
        self::assertSame('candidate', $condition->status);
        $native = new Evidence('evidence:1', 1, 2, 3, $this->sourceVersion('b'), 'document:1', 'document', 28,
            producerName: 'native_numeric_parser', fingerprint: str_repeat('d', 64));
        self::assertFalse((new NativeNumericFactProof)->matches($condition, $native));
        $this->expectException(InvalidArgumentException::class);
        new Fact('fact:wrong-source', 1, 2, 3, $this->sourceVersion('a'), 'quantity:document1',
            FactVocabulary::engineeringParameterType($minimum), '102', 'mm', 0.0, 'document', 'candidate', ['evidence:1'],
            semanticAttributes: $minimum);
    }

    #[Test]
    public function legacy_native_measurement_proof_keeps_its_existing_contract(): void
    {
        $proof = ['entity_id' => 'equipment:1', 'source_version' => $this->sourceVersion('a'),
            'parameter' => 'equipment_count', 'evidence_fingerprint' => str_repeat('b', 64), 'measurement' => 'count:3'];
        $fact = new Fact('fact:count', 1, 2, 3, $this->sourceVersion('a'), 'equipment:1', 'equipment_count', '3', 'count',
            0, 'document', 'confirmed', ['evidence:1'], nativeNumericProofs: ['evidence:1' => $proof]);
        $native = new Evidence('evidence:1', 1, 2, 3, $this->sourceVersion('a'), 'document:1', 'document', 1,
            producerName: 'native_numeric_parser', fingerprint: str_repeat('b', 64));
        self::assertTrue((new NativeNumericFactProof)->matches($fact, $native));
        $changed = new Evidence('evidence:1', 1, 2, 3, $this->sourceVersion('a'), 'document:1', 'document', 1,
            producerName: 'native_numeric_parser', fingerprint: str_repeat('c', 64));
        self::assertFalse((new NativeNumericFactProof)->matches($fact, $changed));
    }

    private function engineeringAttributes(string $name = 'Размер B', string $role = 'observed_value'): array
    {
        return FactVocabulary::engineeringParameterAttributes(1, 2, 3,
            ['entity_id' => 'equipment:1', 'entity_source_version' => $this->sourceVersion('a'),
                'work_key' => 'generic.install', 'work_binding_hash' => str_repeat('c', 64)], $name, $role,
            ['evidence_id' => 'evidence:1', 'source_version' => $this->sourceVersion('a'), 'evidence_fingerprint' => str_repeat('b', 64)]);
    }

    private function engineeringFact(array $attributes, mixed $value = '102', ?string $unit = 'mm',
        string $origin = 'user_input', string $status = 'candidate', array $proofs = []): Fact
    {
        return new Fact('fact:parameter', 1, 2, 3, $this->sourceVersion('a'), 'equipment:1',
            FactVocabulary::engineeringParameterType($attributes), $value, $unit, 0.0, $origin, $status, ['evidence:1'],
            nativeNumericProofs: $proofs, semanticAttributes: $attributes);
    }

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
                $this->operand('fact:length', '5', 'm', 3, ['evidence:length']),
                $this->operand('fact:height', '3', 'm', 2, ['evidence:height']),
                $this->operand('fact:openings', '2.4', 'm2', 7, ['evidence:opening']),
            ],
            value: '12.60000000',
            unit: 'm2',
            roundingMode: 'half_up',
            roundingScale: 2,
            evidenceIds: ['evidence:length', 'evidence:height', 'evidence:opening'],
            status: 'confirmed',
        );

        self::assertSame('12.6', $quantity->value);
        self::assertSame('m2', $quantity->unit);
        self::assertSame(3, count($quantity->operands));
        self::assertSame('half_up', $quantity->roundingMode);
    }

    #[Test]
    public function derived_quantity_preserves_decimal_precision_without_float_conversion(): void
    {
        $quantity = new DerivedQuantity(
            id: 'quantity:decimal',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            sourceVersion: $this->sourceVersion('a'),
            entityId: 'entity:wall-1',
            formula: 'small + large',
            operands: [
                $this->operand('fact:small', '0.1', 'm', 1, ['evidence:small']),
                $this->operand('fact:large', '9999999999999999.12345678', 'm', 1, ['evidence:large']),
            ],
            value: '9999999999999999.22345678',
            unit: 'm',
            roundingMode: 'half_even',
            roundingScale: 8,
            evidenceIds: ['evidence:small', 'evidence:large'],
            status: 'confirmed',
        );

        self::assertSame('9999999999999999.22345678', $quantity->value);
        self::assertSame('0.1', $quantity->operands[0]['value']);
    }

    #[Test]
    public function decimal_contract_matches_numeric_32_12_before_persistence(): void
    {
        foreach ([
            '99999999999999999999.999999999999',
            '-99999999999999999999.999999999999',
            '0',
            '-0.000000000001',
        ] as $value) {
            self::assertNotNull($this->quantityWithValue($value, 12)->value);
        }

        foreach ([
            ['100000000000000000000', 0],
            ['1.0000000000001', 12],
            ['1', 13],
        ] as [$value, $scale]) {
            try {
                $this->quantityWithValue($value, $scale);
                self::fail($value.' was accepted outside numeric(32,12).');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function confirmed_derived_quantity_must_already_match_declared_rounding_scale(): void
    {
        foreach ([['1.23', 2], ['1.2', 2], ['-1.23', 2], ['1.2300', 2], ['1.123456789012', 12]] as [$value, $scale]) {
            self::assertSame(rtrim(rtrim($value, '0'), '.'), $this->quantityWithValue($value, $scale)->value);
        }

        foreach ([['1.234', 2], ['1.1', 0], ['-1.234', 2], ['1.1234567890123', 12]] as [$value, $scale]) {
            try {
                $this->quantityWithValue($value, $scale);
                self::fail($value.' was accepted with rounding scale '.$scale.'.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function repository_rechecks_rounding_scale_before_any_persistence_access(): void
    {
        $reflection = new \ReflectionClass(DerivedQuantity::class);
        /** @var DerivedQuantity $quantity */
        $quantity = $reflection->newInstanceWithoutConstructor();
        foreach (['value' => '1.234', 'status' => 'confirmed', 'roundingScale' => 2] as $property => $value) {
            $reflection->getProperty($property)->setValue($quantity, $value);
        }
        $repository = new EloquentProjectModelRepository($this->createMock(DatabaseManager::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rounding scale');
        $repository->appendDerivedQuantities([$quantity]);
    }

    #[Test]
    public function derived_quantity_becomes_unresolved_when_any_operand_is_not_current_confirmed_and_evidenced(): void
    {
        foreach ([
            ['candidate', true, ['evidence:input'], null],
            ['unresolved', true, ['evidence:input'], null],
            ['conflicted', true, ['evidence:input'], null],
            ['confirmed', false, ['evidence:input'], null],
            ['confirmed', true, [], null],
        ] as [$status, $current, $evidence, $decisionId]) {
            $operand = $this->operand('fact:input', '1.25', 'm', 4, $evidence, $status, $current, $decisionId);
            $quantity = new DerivedQuantity(
                'quantity:unresolved-'.$status.'-'.($current ? 'current' : 'stale').'-'.count($evidence),
                1,
                2,
                3,
                $this->sourceVersion('a'),
                'entity:wall-1',
                'input',
                [$operand],
                '1.25',
                'm',
                'half_up',
                2,
                $evidence,
                'confirmed',
            );

            self::assertSame('unresolved', $quantity->status);
            self::assertNull($quantity->value);
            self::assertSame(['fact:input'], $quantity->unresolvedInputs);
        }
    }

    #[Test]
    public function explicit_user_decision_can_supply_operand_lineage_without_document_evidence(): void
    {
        $quantity = new DerivedQuantity(
            'quantity:decision-backed',
            1,
            2,
            3,
            $this->sourceVersion('a'),
            'entity:wall-1',
            'input',
            [$this->operand('fact:input', '1.25', 'm', 4, [], 'confirmed', true, 'decision:input')],
            '1.25',
            'm',
            'half_up',
            2,
            [],
            'confirmed',
        );

        self::assertSame('confirmed', $quantity->status);
        self::assertSame([], $quantity->unresolvedInputs);
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

    private function operand(
        string $factId,
        string $value,
        string $unit,
        int $projectionVersion,
        array $evidenceIds,
        string $status = 'confirmed',
        bool $current = true,
        ?string $decisionId = null,
    ): array {
        return [
            'fact_id' => $factId,
            'projection_version' => $projectionVersion,
            'status' => $status,
            'current' => $current,
            'value' => $value,
            'unit' => $unit,
            'evidence_ids' => $evidenceIds,
            'decision_id' => $decisionId,
        ];
    }

    private function quantityWithValue(string $value, int $roundingScale): DerivedQuantity
    {
        return new DerivedQuantity(
            'quantity:decimal-boundary', 1, 2, 3, $this->sourceVersion('a'), 'entity:wall-1',
            'input', [$this->operand('fact:input', $value, 'm', 1, ['evidence:input'])],
            $value, 'm', 'half_up', $roundingScale, ['evidence:input'], 'confirmed',
        );
    }
}
