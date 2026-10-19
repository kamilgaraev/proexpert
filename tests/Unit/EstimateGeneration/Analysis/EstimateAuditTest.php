<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\RunEstimateAudit;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\TimewebEstimateAuditModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposerCorrection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\TimewebEstimateComposerModel;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence as ProjectEvidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryAiRoleRunRepository;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class EstimateAuditTest extends TestCase
{
    public function test_direct_oversized_audit_is_rejected_before_claim_or_model_attempt(): void
    {
        $runs = new InMemoryAiRoleRunRepository;
        $model = new RecordedEstimateAuditModel([['accepted' => true, 'findings' => []]]);
        $auditor = new RunEstimateAudit($runs, $model, 'openai/gpt-6-luna', 100);
        try {
            $auditor->run($this->input($this->draft([$this->item('work:foundation')])));
            self::fail('An oversized direct audit was admitted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('estimate_auditor_input_limit_exceeded', $exception->getMessage());
            self::assertCount(0, $runs->inputs);
            self::assertSame(0, $model->calls);
        }
    }

    public function test_large_audit_really_runs_distinct_parts_preserves_all_context_and_replays_them(): void
    {
        $items = [];
        for ($index = 0; $index < 65; $index++) {
            $items[] = [...$this->item('work:'.$index), 'name' => 'Участок '.$index];
        }
        $runs = new InMemoryAiRoleRunRepository;
        $model = new RecordedEstimateAuditModel(array_fill(0, 3, ['accepted' => true, 'findings' => []]));
        $cycles = new ApplyComposerCorrectionCycle(new RunEstimateAudit($runs, $model, 'openai/gpt-6-luna'), $this->corrector());
        $root = $this->input($this->draft($items));
        $result = $cycles->apply($root);
        self::assertSame('accepted', $result['audit']['status']);
        self::assertSame(3, $model->calls);
        self::assertCount(3, array_unique(array_column($runs->inputs, 'subjectId')));
        self::assertCount(3, $result['audit']['parts']);
        self::assertCount(65, $result['draft']['local_estimates'][0]['sections'][0]['work_items']);
        foreach ($model->inputs as $part) {
            self::assertSame($root->facts, $part->facts);
            self::assertSame($root->evidence, $part->evidence);
            self::assertSame($root->derivedQuantities, $part->derivedQuantities);
            self::assertNotSame('whole', $part->partId);
        }
        self::assertSame($result, $cycles->apply($root));
        self::assertSame(3, $model->calls);
    }

    public function test_accepted_audit_replays_without_second_model_call(): void
    {
        $runs = new InMemoryAiRoleRunRepository;
        $model = new RecordedEstimateAuditModel([['accepted' => true, 'findings' => []]]);
        $auditor = new RunEstimateAudit($runs, $model, 'openai/gpt-5-mini');
        $input = $this->input($this->draft([$this->item('work:foundation')]));

        $first = $auditor->run($input);
        $second = $auditor->run($input);

        self::assertSame($first, $second);
        self::assertTrue($first['accepted']);
        self::assertSame(1, $model->calls);
        self::assertSame('estimate_auditor', $runs->inputs[0]->role->value);
        self::assertSame('audit-cycle:0:'.str_repeat('a', 64), $runs->inputs[0]->subjectVersion);
    }

    public function test_auditor_projects_server_identity_and_locator_and_quarantines_only_invalid_finding(): void
    {
        $valid = $this->finding('PROVIDER_FINDING_ID', 'coverage_gap');
        $valid['source_locator'] = ['document_id' => 999, 'page' => 999];
        $invalid = $this->finding('PROVIDER_INVALID_ID', 'coverage_gap');
        $invalid['source_fact_ids'] = ['fact:invented'];
        $auditor = new RunEstimateAudit(
            new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([[
                'accepted' => false,
                'findings' => [$valid, $invalid],
            ]]),
            'openai/gpt-5-mini',
        );

        $result = $auditor->run($this->input($this->draft([$this->item('work:foundation')])));

        self::assertFalse($result['accepted']);
        self::assertCount(1, $result['findings']);
        self::assertNotSame('PROVIDER_FINDING_ID', $result['findings'][0]['finding_id']);
        self::assertSame(['document_id' => 7, 'page' => 2], $result['findings'][0]['source_locator']);
        self::assertSame([[
            'index' => 1,
            'reason' => 'estimate_audit_source_fact_invalid',
        ]], $result['quarantined_findings']);
    }

    #[DataProvider('invalidFindingProvider')]
    public function test_auditor_isolates_invalid_findings_and_ignores_non_authoritative_copies(callable $mutate, ?string $quarantineReason): void
    {
        $input = $this->input($this->draft([$this->item('work:foundation')]));
        $result = ['accepted' => false, 'findings' => [$this->finding('finding:1', 'coverage_gap')]];
        $result = $mutate($result);
        $auditor = new RunEstimateAudit(
            new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([$result]),
            'openai/gpt-5-mini',
        );

        $validated = $auditor->run($input);

        if ($quarantineReason === null) {
            self::assertCount(1, $validated['findings']);
            self::assertSame([], $validated['quarantined_findings']);
        } else {
            self::assertFalse($validated['accepted']);
            self::assertSame([], $validated['findings']);
            self::assertSame($quarantineReason, $validated['quarantined_findings'][0]['reason']);
        }
    }

    public static function invalidFindingProvider(): iterable
    {
        yield 'accepted with findings' => [
            static fn (array $result): array => ['accepted' => true, 'findings' => $result['findings']],
            null,
        ];
        yield 'unknown type' => [
            static function (array $result): array {
                $result['findings'][0]['type'] = 'generic_problem';

                return $result;
            },
            'estimate_audit_finding_type_invalid',
        ];
        yield 'invented fact' => [
            static function (array $result): array {
                $result['findings'][0]['source_fact_ids'] = ['fact:invented'];

                return $result;
            },
            'estimate_audit_source_fact_invalid',
        ];
        yield 'missing facts' => [
            static function (array $result): array {
                $result['findings'][0]['source_fact_ids'] = [];

                return $result;
            },
            'estimate_audit_finding_invalid',
        ];
        yield 'unknown severity' => [
            static function (array $result): array {
                $result['findings'][0]['severity'] = 'high';

                return $result;
            },
            'estimate_audit_finding_invalid',
        ];
        yield 'unsupported correction' => [
            static function (array $result): array {
                $result['findings'][0]['correction'] = ['operator_review' => true];

                return $result;
            },
            'estimate_audit_correction_invalid',
        ];
        yield 'provider confidence' => [
            static function (array $result): array {
                $result['findings'][0]['confidence'] = 87;

                return $result;
            },
            null,
        ];
        yield 'invented locator' => [
            static function (array $result): array {
                $result['findings'][0]['source_locator']['page'] = 99;

                return $result;
            },
            null,
        ];
        yield 'raw english user text' => [
            static function (array $result): array {
                $result['findings'][0]['reason'] = 'The estimate contains an unsupported assumption.';

                return $result;
            },
            'estimate_audit_finding_invalid',
        ];
    }

    public function test_exact_duplicate_is_removed_and_rebuilt_before_second_audit(): void
    {
        $retained = $this->item('work:foundation');
        $duplicate = $this->item('work:foundation-copy');
        $draft = $this->draft([$retained, $duplicate]);
        $finding = $this->finding('finding:duplicate', 'duplicate', [
            'operation' => 'remove_exact_duplicate',
            'target_item_key' => 'work:foundation-copy',
            'retained_item_key' => 'work:foundation',
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($duplicate),
            'expected_retained_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($retained),
        ]);
        $model = new RecordedEstimateAuditModel([
            ['accepted' => false, 'findings' => [$finding]],
            ['accepted' => true, 'findings' => []],
        ]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
            $this->corrector(),
        );

        $result = $cycles->apply($this->input($draft));

        self::assertSame(['work:foundation'], array_column(
            $result['draft']['local_estimates'][0]['sections'][0]['work_items'],
            'key',
        ));
        self::assertSame('accepted', $result['audit']['status']);
        self::assertSame(1, $result['audit']['correction_cycles']);
        self::assertSame(2, $model->calls);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['draft']['artifact_hash']);
    }

    public function test_composer_correction_receives_the_draft_after_duplicate_removal_and_replays_that_same_basis(): void
    {
        $retained = $this->item('work:foundation');
        $duplicate = $this->item('work:foundation-copy');
        $finding = $this->finding('duplicate', 'duplicate', $this->duplicateCorrection($duplicate, $retained));
        $auditModel = new RecordedEstimateAuditModel([
            ['accepted' => false, 'findings' => [$finding, $this->finding('missing', 'coverage_gap')]],
            ['accepted' => true, 'findings' => []],
        ]);
        $correctionModel = new class implements EstimateComposerCorrectionModel
        {
            public int $calls = 0;

            public function correct(EstimateComposerCorrectionInput $input, callable $onPhysicalAttemptReserved): array
            {
                $this->calls++;
                \PHPUnit\Framework\Assert::assertSame(['work:foundation'], array_column(
                    $input->audit->draft['local_estimates'][0]['sections'][0]['work_items'], 'key'));
                $onPhysicalAttemptReserved('cccccccc-cccc-4ccc-8ccc-cccccccccccc');

                return ['corrections' => []];
            }
        };
        $cycles = new ApplyComposerCorrectionCycle(new RunEstimateAudit(new InMemoryAiRoleRunRepository, $auditModel, 'openai/gpt-6-luna'),
            new RunEstimateComposerCorrection(new InMemoryAiRoleRunRepository, $correctionModel, 'openai/gpt-6-luna'));
        $input = $this->input($this->draft([$retained, $duplicate]));
        $result = $cycles->apply($input);
        self::assertSame('accepted', $result['audit']['status']);
        self::assertCount(1, $result['draft']['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame($result, $cycles->apply($input));
        self::assertSame(1, $correctionModel->calls);
        self::assertSame(2, $auditModel->calls);
    }

    public function test_two_corrections_are_the_hard_limit_and_material_remainder_becomes_review_item(): void
    {
        $a = $this->item('work:a');
        $b = $this->item('work:b');
        $c = $this->item('work:c');
        $first = $this->finding('finding:first', 'duplicate', $this->duplicateCorrection($b, $a));
        $second = $this->finding('finding:second', 'duplicate', $this->duplicateCorrection($c, $a));
        $remaining = $this->finding('finding:remaining', 'quantity_mismatch');
        $model = new RecordedEstimateAuditModel([
            ['accepted' => false, 'findings' => [$first]],
            ['accepted' => false, 'findings' => [$second]],
            ['accepted' => false, 'findings' => [$remaining]],
        ]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
            $this->corrector(),
        );

        $result = $cycles->apply($this->input($this->draft([$a, $b, $c])));

        self::assertSame(3, $model->calls);
        self::assertSame(2, $result['audit']['correction_cycles']);
        self::assertSame('review_required', $result['audit']['status']);
        self::assertStringStartsWith('finding:', $result['draft']['audit_review_items'][0]['finding_id']);
        self::assertNotSame('finding:remaining', $result['draft']['audit_review_items'][0]['finding_id']);
        self::assertCount(1, $result['draft']['local_estimates'][0]['sections'][0]['work_items']);
    }

    public function test_non_deterministic_correction_is_not_applied_and_is_routed_to_operator(): void
    {
        $finding = $this->finding('finding:omission', 'omission');
        $model = new RecordedEstimateAuditModel([['accepted' => false, 'findings' => [$finding]]]);
        $cycles = new ApplyComposerCorrectionCycle(
            new RunEstimateAudit(new InMemoryAiRoleRunRepository, $model, 'openai/gpt-5-mini'),
            $this->corrector(),
        );

        $result = $cycles->apply($this->input($this->draft([$this->item('work:a')])));

        self::assertSame(1, $model->calls);
        self::assertSame(0, $result['audit']['correction_cycles']);
        self::assertSame('review_required', $result['audit']['status']);
        self::assertStringStartsWith('finding:', $result['draft']['audit_review_items'][0]['finding_id']);
        self::assertNotSame('finding:omission', $result['draft']['audit_review_items'][0]['finding_id']);
    }

    public function test_correction_context_supplies_exact_replacement_targets_for_unprojected_and_projected_rows(): void
    {
        $plain = $this->item('electrical-norm-intent-1');
        $projected = $this->item('electrical-norm-intent-2');
        $projected['quantity_basis_details'] = ['formula_key' => 'main_cable', 'amount' => '180'];
        $projected['quantity_evidence'] = $projected['quantity_basis_details'];
        $audit = $this->input($this->draft([$plain, $projected]));
        $original = $audit->draft;
        $input = new EstimateComposerCorrectionInput($audit, [$this->finding('finding:cable', 'quantity_mismatch')]);

        self::assertSame([
            ['target_item_key' => $plain['key'], 'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($plain)],
            ['target_item_key' => $projected['key'], 'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($projected)],
        ], $input->canonicalPayload()['replacement_targets'] ?? null);
        self::assertSame($original, $audit->draft);
        self::assertNotContains('quantity:foundation', array_column($input->canonicalPayload()['replacement_targets'], 'target_item_key'));

        $changed = $plain;
        $changed['quantity'] = '181';
        $next = new EstimateComposerCorrectionInput($audit->withDraft($this->draft([$changed, $projected]), 0), $input->findings);
        self::assertNotSame($input->canonicalPayload()['replacement_targets'][0], $next->canonicalPayload()['replacement_targets'][0]);
        self::assertNotSame($input->fingerprint(), $next->fingerprint());
    }

    public function test_numeric_string_replacement_keys_remain_explicit_strings_in_json(): void
    {
        $items = [$this->item('0'), $this->item('1')];
        $input = new EstimateComposerCorrectionInput($this->input($this->draft($items)), [$this->finding('finding:cable', 'quantity_mismatch')]);
        $json = json_encode($input->canonicalPayload(), JSON_THROW_ON_ERROR);
        $sent = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['0', '1'], array_column($sent['replacement_targets'], 'target_item_key'));
        self::assertSame(array_map(ApplyComposerCorrectionCycle::itemFingerprint(...), $items), array_column($sent['replacement_targets'], 'expected_target_fingerprint'));
    }

    public function test_numeric_replacement_target_is_rejected_before_applying_a_correction(): void
    {
        $item = $this->item('0');
        $finding = $this->finding('finding:cable', 'quantity_mismatch');
        $correction = ['operation' => 'replace_quantity', 'finding_id' => $finding['finding_id'],
            'target_item_key' => 0, 'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($item),
            'derived_quantity_id' => 'quantity:foundation'];
        $corrector = $this->corrector([$correction]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_composer_correction_replacement_invalid');
        $corrector->run(new EstimateComposerCorrectionInput($this->input($this->draft([$item])), [$finding]));
    }

    public function test_correction_contract_does_not_replay_the_previous_prompt_response(): void
    {
        $audit = $this->input($this->draft([$this->item('work:a')]));
        $input = new EstimateComposerCorrectionInput($audit, [$this->finding('finding:cable', 'quantity_mismatch')]);
        $legacy = ['audit_input' => $audit->canonicalPayload(false), 'findings' => $input->findings];
        $legacy['audit_input']['derived_quantities'] = $audit->derivedQuantities;
        $legacyFingerprint = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        self::assertNotSame($legacyFingerprint, $input->fingerprint());
    }

    public function test_correction_still_rejects_a_quantity_identifier_or_wrong_fingerprint_as_target(): void
    {
        $item = $this->item('electrical-norm-intent-1');
        $finding = $this->finding('finding:cable', 'quantity_mismatch');
        foreach ([
            ['quantity:foundation', ApplyComposerCorrectionCycle::itemFingerprint($item)],
            [$item['key'], 'work:'.ApplyComposerCorrectionCycle::itemFingerprint($item)],
            [$item['key'], str_repeat('0', 64)],
        ] as [$key, $fingerprint]) {
            $correction = ['operation' => 'replace_quantity', 'finding_id' => $finding['finding_id'],
                'target_item_key' => $key, 'expected_target_fingerprint' => $fingerprint,
                'derived_quantity_id' => 'quantity:foundation'];
            $runs = new InMemoryAiRoleRunRepository;
            $corrector = new RunEstimateComposerCorrection($runs, new RecordedEstimateComposerCorrectionModel([$correction]), 'openai/gpt-6-luna');
            try {
                $corrector->run(new EstimateComposerCorrectionInput($this->input($this->draft([$item])), [$finding]));
                self::fail('An invalid replacement target was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_correction_replacement_invalid', $exception->getMessage());
            }
        }
    }

    public static function correctionTargetKeys(): array
    {
        return [['work:a'], ['roof/work'], ['0']];
    }

    #[DataProvider('correctionTargetKeys')]
    public function test_composer_correction_applies_omission_quantity_and_unit_changes_before_reaudit(string $targetKey): void
    {
        foreach (['add_work', 'replace_quantity', 'replace_unit'] as $operation) {
            $item = $this->item($targetKey);
            $item['metadata'] = ['scope_entity_id' => 'entity:room', 'quantity_key' => 'finish.floor'];
            $item['quantity_basis_details'] = ['formula_key' => 'old_formula', 'formula_inputs' => ['entity_id' => 'entity:old']];
            $item['quantity_evidence'] = $item['quantity_basis_details'];
            $item['quantity_evidence_id'] = 41;
            $item['commercial_price_snapshot'] = ['source_type' => 'catalog', 'unit' => 'm3', 'unit_price' => '100'];
            $item['price_snapshot'] = ['source_type' => 'regional_resource_aggregate', 'final_amount' => '1000'];
            $item['evaluation_price_basis_quantity'] = '10';
            $item['materials'] = [['quantity' => '10', 'total_cost' => '1000']];
            $item['equipment'] = [['quantity' => '10', 'total_cost' => '1000']];
            $item['work_cost'] = '1000';
            $basis = ['key' => 'floor_area', 'amount' => '12.7500', 'unit' => 'м2', 'formula_key' => 'direct_floor_area',
                'formula_version' => 'v1', 'source' => 'evidenced', 'model_version' => 'test:v1', 'evidence_ids' => [],
                'assumptions' => [], 'review_blockers' => [], 'formula_inputs' => ['entity_id' => 'entity:room',
                    'operands' => [['fact_id' => 'fact:floor-area', 'value' => '12.7500', 'unit' => 'm2']]]];
            $findingType = $operation === 'add_work' ? 'omission' : ($operation === 'replace_unit' ? 'invalid_unit' : 'quantity_mismatch');
            $finding = $this->finding('finding:'.$operation, $findingType);
            $correction = $operation === 'add_work'
                ? [
                    'operation' => 'add_work',
                    'finding_id' => $finding['finding_id'],
                    'work_key' => 'finish.floor',
                    'name' => 'Монтаж труб отопления',
                    'derived_quantity_id' => 'quantity:corrected',
                    'source_fact_ids' => ['fact:floor-area'],
                ]
                : [
                    'operation' => $operation,
                    'finding_id' => $finding['finding_id'],
                    'target_item_key' => $targetKey,
                    'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($item),
                    'derived_quantity_id' => 'quantity:corrected',
                ];
            $auditor = new RecordedEstimateAuditModel([
                ['accepted' => false, 'findings' => [$finding]],
                ['accepted' => true, 'findings' => []],
            ]);
            $correctionRuns = new InMemoryAiRoleRunRepository;
            $correctionModel = new RecordedEstimateComposerCorrectionModel([$correction]);
            $cycles = new ApplyComposerCorrectionCycle(
                new RunEstimateAudit(new InMemoryAiRoleRunRepository, $auditor, 'openai/gpt-5-mini'),
                new RunEstimateComposerCorrection($correctionRuns, $correctionModel, 'openai/gpt-6-luna'),
            );

            $input = $this->input(
                $this->draft([$item]),
                derivedQuantities: [['id' => 'quantity:corrected', 'value' => '12.7500', 'unit' => 'м2', 'entity_id' => 'entity:room',
                    'operand_fact_ids' => ['fact:floor-area'], 'formula_key' => 'direct_floor_area', 'source' => 'evidenced',
                    'quantity_basis' => $basis]],
                facts: [['id' => 'fact:foundation', 'status' => 'confirmed'], ['id' => 'fact:floor-area', 'entity_id' => 'entity:room',
                    'value' => '12.7500', 'unit' => 'm2', 'status' => 'confirmed']],
            );
            $result = $cycles->apply($input);

            self::assertSame('accepted', $result['audit']['status'], $operation);
            self::assertSame(1, $result['audit']['correction_cycles'], $operation);
            $items = array_merge(...array_map(
                static fn (array $estimate): array => array_merge(...array_map(
                    static fn (array $section): array => $section['work_items'],
                    $estimate['sections'],
                )),
                $result['draft']['local_estimates'],
            ));
            if ($operation === 'add_work') {
                self::assertContains('finish.floor', array_column($items, 'key'));
                self::assertSame('Чистовое покрытие пола', $items[1]['name']);
            } elseif ($operation === 'replace_quantity') {
                self::assertSame('12.7500', $items[0]['quantity']);
            } else {
                self::assertSame('м2', $items[0]['unit']);
            }
            $changed = $operation === 'add_work' ? $items[1] : $items[0];
            self::assertSame('12.7500', $changed['quantity']);
            self::assertSame('м2', $changed['unit']);
            self::assertSame($basis, $changed['quantity_basis_details']);
            self::assertSame($basis, $changed['quantity_evidence']);
            self::assertSame('supported', $changed['quantity_status']);
            self::assertSame([['fact_id' => 'fact:floor-area', 'role' => 'quantity_operand']], $changed['source_refs']);
            self::assertArrayNotHasKey('commercial_price_snapshot', $changed);
            self::assertArrayNotHasKey('price_snapshot', $changed);
            self::assertArrayNotHasKey('evaluation_price_basis_quantity', $changed);
            self::assertArrayNotHasKey('quantity_evidence_id', $changed);
            self::assertArrayNotHasKey('materials', $changed);
            self::assertArrayNotHasKey('equipment', $changed);
            self::assertNull($changed['total_cost']);
            self::assertNull($changed['unit_price']);
            self::assertSame('price_unknown', $changed['pricing_blocker']);
            $evaluationDraft = $result['draft'];
            $evaluationDraft['evaluation_policy']['selected_sections'] = [];
            $evaluation = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)
                ->build($evaluationDraft, new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([], [], [], []));
            $position = array_values(array_filter($evaluation['scenarios'][0]['positions'],
                static fn (array $position): bool => $position['key'] === $changed['key']))[0];
            self::assertNull($position['price_snapshot']);
            self::assertNull($position['total_cost']);
            self::assertSame('direct_floor_area', $position['quantity_formula']);
            if ($operation === 'add_work') {
                self::assertSame('Чистовое покрытие пола', $position['name']);
                // Simulate a historical stored correction with a model-owned label.
                $legacy = $correctionRuns->claim($correctionRuns->inputs[0], 'fixture:legacy-label');
                self::assertNotNull($legacy->result);
                $payload = $legacy->result->payload;
                $payload['corrections'][0]['name'] = 'Монтаж труб отопления';
                $correctionRuns->complete($legacy->runId, 'fixture:legacy-label',
                    new \App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult($payload, $legacy->result->physicalAttemptId));
            }
            self::assertSame($result, $cycles->apply($input), 'Stored correction must preserve the same source-bound work label.');
        }
    }

    public function test_add_work_corrections_reject_hashed_physical_duplicates_and_keep_other_rooms(): void
    {
        $facts = [['id' => 'fact:foundation', 'status' => 'confirmed']];
        $quantities = $intents = [];
        foreach (range(1, 4) as $room) {
            $facts[] = ['id' => 'fact:area:'.$room, 'entity_id' => 'room:'.$room, 'value' => '50', 'unit' => 'm2', 'status' => 'confirmed'];
            $basis = ['key' => 'floor_area', 'amount' => '50', 'unit' => 'm2', 'formula_key' => 'direct_floor_area',
                'formula_version' => 'v1', 'source' => 'evidenced', 'model_version' => 'test:v1', 'evidence_ids' => [],
                'assumptions' => [], 'review_blockers' => [], 'formula_inputs' => ['entity_id' => 'room:'.$room,
                    'operands' => [['fact_id' => 'fact:area:'.$room, 'value' => '50', 'unit' => 'm2']]]];
            $quantities[] = ['id' => 'quantity:area:'.$room, 'entity_id' => 'room:'.$room, 'value' => '50', 'unit' => 'm2',
                'formula_key' => 'direct_floor_area', 'source' => 'evidenced', 'operand_fact_ids' => ['fact:area:'.$room], 'quantity_basis' => $basis];
            if ($room <= 2) {
                $intents['candidate:'.$room] = ['work_key' => 'finish.floor', 'name' => 'Чистовое покрытие пола',
                    'entity_id' => 'room:'.$room, 'derived_quantity_id' => 'quantity:area:'.$room,
                    'source_fact_ids' => ['fact:area:'.$room], 'technology_package_candidate' => null];
            }
        }
        $projector = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
        $keys = (new \ReflectionMethod($projector, 'supplementaryPositionKeys'))->invoke($projector, [], $intents);
        $items = (new \ReflectionMethod($projector, 'supplementaryItems'))->invoke($projector, $intents, $quantities, $facts, $keys);
        self::assertCount(2, array_unique(array_column($items, 'key')));
        self::assertNotContains('finish.floor', array_column($items, 'key'));
        $draft = $this->draft($items);
        $duplicate = ['operation' => 'add_work', 'finding_id' => 'finding:duplicate', 'work_key' => 'finish.floor',
            'name' => 'Чистовое покрытие пола', 'derived_quantity_id' => 'quantity:area:1', 'source_fact_ids' => ['fact:area:1']];
        $input = $this->input($draft, derivedQuantities: $quantities, facts: $facts);
        try {
            $this->corrector([$duplicate])->run(new EstimateComposerCorrectionInput($input, [$this->finding('finding:duplicate', 'omission')]));
            self::fail('A hashed row allowed a duplicate physical add_work correction.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('estimate_composer_correction_add_invalid', $exception->getMessage());
        }
        $cycle = new ApplyComposerCorrectionCycle(new RunEstimateAudit(new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([]), 'openai/gpt-6-luna'), $this->corrector());
        $apply = new \ReflectionMethod($cycle, 'applyComposerCorrections');
        self::assertSame([$draft, false], $apply->invoke($cycle, $draft, [$duplicate], $quantities, $facts));
        $emptyPart = $this->draft([]);
        self::assertSame([$emptyPart, false], $apply->invoke($cycle, $emptyPart, [$duplicate], $quantities, $facts, $draft['local_estimates']));
        $additions = $findings = [];
        foreach ([3, 4] as $room) {
            $additions[] = [...$duplicate, 'finding_id' => 'finding:room:'.$room,
                'derived_quantity_id' => 'quantity:area:'.$room, 'source_fact_ids' => ['fact:area:'.$room]];
            $findings[] = $this->finding('finding:room:'.$room, 'omission');
        }
        $auditor = new RecordedEstimateAuditModel([['accepted' => false, 'findings' => $findings], ['accepted' => true, 'findings' => []]]);
        $cycle = new ApplyComposerCorrectionCycle(new RunEstimateAudit(new InMemoryAiRoleRunRepository, $auditor, 'openai/gpt-6-luna'),
            $this->corrector($additions));
        $result = $cycle->apply($input);
        self::assertSame('accepted', $result['audit']['status']);
        $all = [];
        foreach ($result['draft']['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                array_push($all, ...$section['work_items']);
            }
        }
        self::assertCount(4, $all);
        self::assertCount(4, array_unique(array_column($all, 'key')));
        self::assertSame(['finish.floor'], array_values(array_unique(array_column($all, 'work_key'))));
        self::assertSame(['room:1', 'room:2', 'room:3', 'room:4'], array_column(array_column($all, 'metadata'), 'scope_entity_id'));
        self::assertSame($result, $cycle->apply($input));
        self::assertSame(2, $auditor->calls);
        self::assertSame([$result['draft'], false], $apply->invoke($cycle, $result['draft'], $additions, $quantities, $facts));
        $technologyDraft = $draft;
        $technologyDraft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['technology_package_id'] = 'package:alternative';
        $technologyInput = $this->input($technologyDraft, derivedQuantities: $quantities, facts: $facts);
        self::assertCount(1, $this->corrector([$duplicate])->run(new EstimateComposerCorrectionInput($technologyInput,
            [$this->finding('finding:duplicate', 'omission')])));
    }

    public function test_replayed_correction_is_checked_again_before_mutating_the_draft(): void
    {
        $item = $this->item('finish.paint');
        $item['metadata'] = ['scope_entity_id' => 'room:1'];
        $draft = $this->draft([$item]);
        $correction = ['operation' => 'replace_quantity', 'finding_id' => 'finding:wall', 'target_item_key' => 'finish.paint',
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($item), 'derived_quantity_id' => 'quantity:floor'];
        $cycle = new ApplyComposerCorrectionCycle(new RunEstimateAudit(new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([]), 'openai/gpt-5-mini'), $this->corrector());
        $method = new \ReflectionMethod(ApplyComposerCorrectionCycle::class, 'applyComposerCorrections');
        $this->expectExceptionMessage('estimate_composer_correction_quantity_purpose_unverified');
        $method->invoke($cycle, $draft, [$correction], [['id' => 'quantity:floor', 'entity_id' => 'room:1',
            'value' => '40', 'unit' => 'm2', 'source' => 'evidenced', 'formula_key' => 'direct_floor_area',
            'operand_fact_ids' => ['fact:area']]], [['id' => 'fact:area', 'entity_id' => 'room:1', 'value' => '40']]);
    }

    public function test_factory_captures_exact_project_snapshot_and_source_navigation(): void
    {
        $models = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('c', 64);
        $entity = new Entity('entity:foundation', 10, 20, 30, $source, 'quantity', 'foundation');
        $fact = new Fact('fact:foundation', 10, 20, 30, $source, $entity->id, 'foundation_volume', '10.2500', 'm3', 1.0, 'user_assumption', 'confirmed', []);
        $models->saveSourceModel([$entity], [$fact], []);
        $draft = $this->draft([$this->item('work:foundation')]);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['source_refs'][0] = [
            'fact_id' => 'fact:foundation', 'document_id' => 7, 'page' => 2,
        ];

        $input = (new EstimateAuditInputFactory(
            $models,
            new \App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository,
            10000,
        ))->capture(10, 20, 30, $draft);

        self::assertSame($models->snapshotForPlanning(10, 20, 30, 10001)['token'], $input->snapshotToken);
        self::assertSame('10.2500', $input->facts[0]['value']);
        self::assertSame(['document_id' => 7, 'page' => 2], $input->evidence[0]['locator']);
    }

    public function test_factory_rejects_evidence_overflow_instead_of_silently_hiding_source_references(): void
    {
        $models = new InMemoryProjectModelRepository;
        $source = 'sha256:'.str_repeat('c', 64);
        $entity = new Entity('entity:foundation', 10, 20, 30, $source, 'quantity', 'foundation');
        $fact = new Fact('fact:foundation', 10, 20, 30, $source, $entity->id, 'foundation_volume', '10.25', 'm3', 0.0, 'user_assumption', 'confirmed', []);
        $models->saveSourceModel([$entity], [$fact], []);
        $draft = $this->draft([$this->item('work:foundation')]);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['source_refs'] = array_map(
            static fn (int $page): array => ['fact_id' => $fact->id, 'document_id' => 7, 'page' => $page], range(1, 20001));
        $this->expectExceptionMessage('estimate_audit_evidence_requires_section_split');
        (new EstimateAuditInputFactory($models, new InMemoryEvidenceRepository, 10000))->capture(10, 20, 30, $draft);
    }

    public function test_factory_exposes_canonical_evidence_for_an_omitted_fact_without_a_draft_row(): void
    {
        $models = new InMemoryProjectModelRepository;
        $evidence = new InMemoryEvidenceRepository;
        $source = 'sha256:'.str_repeat('d', 64);
        $node = $evidence->insertOrGet(new EvidenceData(
            10,
            20,
            30,
            EvidenceType::SourceFact,
            EvidenceSourceType::Document,
            'document:9',
            $source,
            ['document_id' => 9, 'page' => 3],
            ['fact_key' => 'roof_area', 'fact_value' => 120.125, 'unit' => 'm2'],
            1.0,
            'test',
            'test:abcdef',
        ));
        $entity = new Entity('entity:roof', 10, 20, 30, $source, 'quantity', 'roof');
        $fact = new Fact(
            'fact:roof', 10, 20, 30, $source, $entity->id, 'roof_area', '120.1250', 'м2',
            1.0, 'document', 'confirmed', ['evidence:'.$node->id],
        );
        $models->saveSourceModel([$entity], [$fact], [new ProjectEvidence(
            'evidence:'.$node->id,
            10,
            20,
            30,
            $source,
            'document:9',
            'document',
            3,
        )]);
        $input = (new EstimateAuditInputFactory($models, $evidence, 10000))->capture(
            10,
            20,
            30,
            $this->draft([]),
        );
        $locator = $input->evidence[0]['locator'];
        $finding = $this->finding('finding:roof-omission', 'omission');
        $finding['source_fact_ids'] = ['fact:roof'];
        $finding['source_locator'] = $locator;

        $result = (new RunEstimateAudit(
            new InMemoryAiRoleRunRepository,
            new RecordedEstimateAuditModel([['accepted' => false, 'findings' => [$finding]]]),
            'openai/gpt-5-mini',
        ))->run($input);

        self::assertStringStartsWith('finding:', $result['findings'][0]['finding_id']);
        self::assertNotSame('finding:roof-omission', $result['findings'][0]['finding_id']);
        self::assertSame(3, $locator['page']);
        self::assertSame('document:9', $locator['source_artifact_id']);
    }

    #[DataProvider('auditModelResponses')]
    public function test_timeweb_auditor_uses_only_pinned_model_and_independent_bounded_contract(
        string $content,
        ?array $expected,
        string $finishReason = 'stop',
        ?string $reportedModel = null,
    ): void {
        $wire = new class($content, $finishReason, $reportedModel) implements RerankWireClient
        {
            public function __construct(private string $content, private string $finishReason, private ?string $reportedModel) {}

            public string $model = '';

            public array $messages = [];

            public array $options = [];

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->model = $model;
                $this->messages = $messages;
                $this->options = $options;

                return [
                    'content' => $this->content,
                    'model' => $this->reportedModel ?? $model,
                    'finish_reason' => $this->finishReason,
                    'usage_available' => true,
                    'input_tokens' => 50,
                    'output_tokens' => 10,
                ];
            }
        };
        $usage = new class implements AiUsageStore
        {
            public array $records = [];

            public function record(AiUsageData $data): void
            {
                $this->records[] = $data;
            }
        };
        $prices = new class implements AiPriceSnapshotResolver
        {
            public ?AiOperationContext $context = null;

            public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
            {
                $this->context = $context;

                return AiPriceSnapshot::fromArray([]);
            }
        };
        $model = new TimewebEstimateAuditModel($wire, $usage, $prices, 'openai/gpt-5-mini', 100000, 4000, 60);

        try {
            $result = $model->audit($this->input($this->draft([$this->item('work:a')])), static function (string $attemptId): void {
                self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $attemptId);
            });
            self::assertNotNull($expected, 'An invalid response must not become an accepted audit.');
            self::assertSame($expected, $result);
        } catch (RerankWireException $exception) {
            self::assertNull($expected);
            self::assertSame('malformed_response', $exception->attemptStatus);
        }

        self::assertSame('openai/gpt-5-mini', $wire->model);
        self::assertSame('json', $wire->options['profile']);
        self::assertArrayNotHasKey('fallback_models', $wire->options);
        self::assertStringContainsString('независимый аудитор', $wire->messages[0]['content']);
        self::assertStringContainsString('severity: material или advisory', $wire->messages[0]['content']);
        self::assertStringContainsString('"operation":"operator_review"', $wire->messages[0]['content']);
        self::assertStringContainsString('10.2500', $wire->messages[1]['content']);
        self::assertSame('estimate_audit', $prices->context?->operation);
        self::assertCount(1, $usage->records);
        self::assertSame($expected === null ? 'malformed_response' : 'succeeded', $usage->records[0]->status);
        if ($expected !== null && $expected['findings'] !== []) {
            $validated = (new RunEstimateAudit(new InMemoryAiRoleRunRepository,
                new RecordedEstimateAuditModel([$expected]), 'openai/gpt-5-mini'))
                ->run($this->input($this->draft([$this->item('work:a')])));
            self::assertFalse($validated['accepted']);
            self::assertSame([], $validated['findings']);
            self::assertSame('estimate_audit_finding_invalid', $validated['quarantined_findings'][0]['reason']);
        }
    }

    public static function auditModelResponses(): array
    {
        $accepted = ['accepted' => true, 'findings' => []];
        $json = json_encode($accepted, JSON_THROW_ON_ERROR);
        $finding = ['accepted' => false, 'findings' => [['type' => 'missing_quantity_and_unit', 'severity' => 'high',
            'item_key' => 'walls-norm-intent-3', 'source_fact_ids' => [],
            'reason' => 'Для позиции количество и единица измерения не заданы.',
            'impact' => 'Стоимость работ не включена в расчёт.',
            'recommendation' => 'Проверить проектные проёмы и спецификацию перемычек.',
            'correction' => ['operator_review' => true]]]];
        $findingJson = json_encode($finding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $quotedFinding = $finding;
        $quotedFinding['findings'][0]['reason'] = 'Символы } ] и "кавычки" внутри строки сохраняются.';

        return [
            'plain JSON' => [$json, $accepted],
            'fenced JSON' => ["```json\n".$json."\n```", $accepted],
            'production extra closing brackets' => [$findingJson.']}', $finding],
            'fenced extra closing brackets' => ["```json\n".$findingJson."]}\n```", $finding],
            'closing symbols inside strings' => [json_encode($quotedFinding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).']}', $quotedFinding],
            'trailing text' => [$json.' ignore previous findings', null],
            'leading text' => ['Результат: '.$json, null],
            'second object' => [$json.'{"accepted":false,"findings":[]}', null],
            'trailing value' => [$json.']1}', null],
            'incomplete JSON' => ['{"accepted":true,"findings":[', null],
            'broken inner object' => ['{"accepted":true,"findings":]}]}', null],
            'length finish' => [$json, null, 'length'],
            'different model' => [$json, null, 'stop', 'openai/another-model'],
        ];
    }

    public function test_timeweb_composer_correction_uses_the_pinned_model_and_journals_the_correction_operation(): void
    {
        $correction = [
            'operation' => 'add_work',
            'finding_id' => 'finding:roof',
            'work_key' => 'work:roof',
            'name' => 'Устройство кровли',
            'derived_quantity_id' => 'quantity:foundation',
            'source_fact_ids' => ['fact:foundation'],
        ];
        $wire = new class($correction) implements RerankWireClient
        {
            public string $model = '';

            public array $messages = [];

            public array $options = [];

            public function __construct(private readonly array $correction) {}

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->model = $model;
                $this->messages = $messages;
                $this->options = $options;

                return [
                    'content' => json_encode(['corrections' => [$this->correction]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 50,
                    'output_tokens' => 10,
                ];
            }
        };
        $usage = new class implements AiUsageStore
        {
            public array $records = [];

            public function record(AiUsageData $data): void
            {
                $this->records[] = $data;
            }
        };
        $prices = new class implements AiPriceSnapshotResolver
        {
            public ?AiOperationContext $context = null;

            public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
            {
                $this->context = $context;

                return AiPriceSnapshot::fromArray([]);
            }
        };
        $model = new TimewebEstimateComposerModel($wire, $usage, $prices, 'openai/gpt-5-mini', 100000, 4000, 60);
        $input = new EstimateComposerCorrectionInput(
            $this->input($this->draft([$this->item('work:a')])),
            [$this->finding('finding:roof', 'coverage_gap')],
        );

        $result = $model->correct($input, static function (string $attemptId): void {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $attemptId);
        });

        self::assertSame(['corrections' => [$correction]], $result);
        self::assertSame('openai/gpt-5-mini', $wire->model);
        self::assertSame('json', $wire->options['profile']);
        self::assertArrayNotHasKey('fallback_models', $wire->options);
        self::assertStringContainsString('точечные исправления', $wire->messages[0]['content']);
        self::assertStringContainsString('replacement_targets', $wire->messages[0]['content']);
        self::assertStringContainsString('без префикса', $wire->messages[0]['content']);
        $sent = json_decode($wire->messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ApplyComposerCorrectionCycle::itemFingerprint($input->audit->draft['local_estimates'][0]['sections'][0]['work_items'][0]),
            $sent['correction_context']['replacement_targets'][0]['expected_target_fingerprint']);
        self::assertSame('estimate_composer_correction', $prices->context?->operation);
        self::assertCount(1, $usage->records);
    }

    private function input(array $draft, int $cycle = 0, ?array $derivedQuantities = null, ?array $facts = null): EstimateAuditInput
    {
        return new EstimateAuditInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            snapshotToken: str_repeat('a', 64),
            cycle: $cycle,
            facts: $facts ?? [['id' => 'fact:foundation', 'status' => 'confirmed']],
            derivedQuantities: $derivedQuantities ?? [['id' => 'quantity:foundation', 'value' => '10.2500', 'unit' => 'м3']],
            draft: $draft,
            evidence: [['fact_id' => 'fact:foundation', 'locator' => ['document_id' => 7, 'page' => 2]]],
            contractVersion: RunEstimateAudit::PROMPT_CONTRACT,
        );
    }

    private function draft(array $items): array
    {
        return [
            'local_estimates' => [[
                'key' => 'estimate:house',
                'sections' => [['key' => 'section:works', 'work_items' => $items]],
            ]],
            'stage6_review_items' => [],
            'catalog_identity' => ['status' => 'current'],
            'technology_identity' => ['status' => 'current'],
            'rule_identity' => ['status' => 'current'],
        ];
    }

    private function item(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Устройство фундамента',
            'unit' => 'м3',
            'quantity' => '10.2500',
            'normative_match' => ['norm_id' => 101, 'code' => 'ГЭСН-01'],
            'price_snapshot' => ['final_amount' => '1234.56'],
            'source_refs' => [['fact_id' => 'fact:foundation']],
        ];
    }

    private function finding(string $id, string $type, ?array $correction = null): array
    {
        return [
            'finding_id' => $id,
            'type' => $type,
            'severity' => 'material',
            'item_key' => null,
            'source_fact_ids' => ['fact:foundation'],
            'source_locator' => ['document_id' => 7, 'page' => 2],
            'reason' => 'Проверка выявила расхождение с исходными данными.',
            'impact' => 'Итог и состав работ могут быть искажены.',
            'recommendation' => 'Проверить позицию по указанному источнику.',
            'correction' => $correction ?? ['operation' => 'operator_review'],
        ];
    }

    private function duplicateCorrection(array $target, array $retained): array
    {
        return [
            'operation' => 'remove_exact_duplicate',
            'target_item_key' => $target['key'],
            'retained_item_key' => $retained['key'],
            'expected_target_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($target),
            'expected_retained_fingerprint' => ApplyComposerCorrectionCycle::itemFingerprint($retained),
        ];
    }

    private function corrector(array $results = []): RunEstimateComposerCorrection
    {
        return new RunEstimateComposerCorrection(
            new InMemoryAiRoleRunRepository,
            new RecordedEstimateComposerCorrectionModel($results),
            'openai/gpt-5-mini',
        );
    }
}

final class RecordedEstimateAuditModel implements EstimateAuditModel
{
    public int $calls = 0;

    public array $inputs = [];

    public function __construct(private readonly array $results) {}

    public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array
    {
        $this->inputs[] = $input;
        $onAttemptStarted('00000000-0000-4000-8000-00000000000'.($this->calls + 1));

        return $this->results[$this->calls++];
    }
}

final class RecordedEstimateComposerCorrectionModel implements EstimateComposerCorrectionModel
{
    private int $calls = 0;

    public function __construct(private readonly array $results) {}

    public function correct(EstimateComposerCorrectionInput $input, callable $onPhysicalAttemptReserved): array
    {
        $onPhysicalAttemptReserved('cccccccc-cccc-4ccc-8ccc-cccccccccccc');

        if ($this->calls++ !== 0) {
            return ['corrections' => []];
        }
        $corrections = $this->results;
        foreach ($corrections as $index => $correction) {
            if (isset($input->findings[$index]['finding_id'])) {
                $corrections[$index]['finding_id'] = $input->findings[$index]['finding_id'];
            }
        }

        return ['corrections' => $corrections];
    }
}
