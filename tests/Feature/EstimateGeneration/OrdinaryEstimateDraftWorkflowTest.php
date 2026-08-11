<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrdinaryEstimateDraftWorkflowTest extends TestCase
{
    #[Test]
    public function valid_quantity_norm_and_regional_price_build_an_ordinary_most_draft_with_bounded_provenance(): void
    {
        $draft = $this->builder()->build($this->draft([$this->validWorkItem()]));

        self::assertSame('most_ordinary_estimate:v1', $draft['generation_contract']);
        self::assertSame('ready', $draft['stage6_status']);
        self::assertTrue($draft['is_complete']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $draft['artifact_hash']);
        self::assertTrue($this->builder()->verifyArtifact($draft));
        self::assertSame([], $draft['stage6_review_items']);
        $metadata = $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['stage6_provenance'];
        self::assertSame('floor_area', $metadata['quantity']['formula_identity']);
        self::assertSame('FSNB-01-001', $metadata['norm']['code']);
        self::assertSame('regional:price:1', $metadata['price']['source_reference']);
        self::assertSame('decision:technology:1', $metadata['technology_decision']['id']);
        self::assertSame(str_repeat('a', 64), $metadata['artifact']['input_snapshot_hash']);
        self::assertSame($draft['artifact_hash'], $metadata['artifact']['artifact_hash']);
        self::assertArrayNotHasKey('prompt', $metadata);
        self::assertLessThanOrEqual(32768, strlen(json_encode($metadata, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function artifact_is_stable_for_associative_permutations_and_changes_with_payload(): void
    {
        $first = $this->builder()->build($this->draft([$this->validWorkItem()]));
        $permutedItem = array_reverse($this->validWorkItem(), true);
        $second = $this->builder()->build(array_reverse($this->draft([$permutedItem]), true));
        self::assertSame($first['artifact_hash'], $second['artifact_hash']);

        $changed = $this->validWorkItem();
        $changed['quantity'] = '12.51';
        $changed['quantity_evidence']['amount'] = '12.51';
        $third = $this->builder()->build($this->draft([$changed]));
        self::assertNotSame($first['artifact_hash'], $third['artifact_hash']);

        $tampered = $first;
        $tampered['local_estimates'][0]['sections'][0]['work_items'][0]['quantity'] = '99';
        self::assertFalse($this->builder()->verifyArtifact($tampered));
    }

    #[Test]
    public function cross_tenant_project_or_source_quantity_lineage_is_blocking(): void
    {
        foreach ([
            ['organization_id' => 999],
            ['project_id' => 999],
            ['session_id' => 999],
            ['source_version' => 'sha256:'.str_repeat('0', 64)],
        ] as $scopeChange) {
            $item = $this->validWorkItem();
            $item['quantity_evidence']['formula_inputs']['operands'][0] = [
                ...$item['quantity_evidence']['formula_inputs']['operands'][0],
                ...$scopeChange,
            ];
            $draft = $this->builder()->build($this->draft([$item]));

            self::assertSame('review_required', $draft['stage6_status']);
            self::assertSame('quantity_not_proven', $draft['stage6_review_items'][0]['code']);
        }
    }

    #[Test]
    public function stale_generation_identity_blocks_every_row_without_publishing_a_total(): void
    {
        foreach (['catalog_identity', 'technology_identity', 'rule_identity'] as $identity) {
            $source = $this->draft([$this->validWorkItem()]);
            $source[$identity]['status'] = 'stale';
            $draft = $this->builder()->build($source);

            self::assertSame('review_required', $draft['stage6_status']);
            self::assertContains('generation_identity_not_current', array_column($draft['stage6_review_items'], 'code'));
            self::assertSame('0', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['total_cost']);
        }
    }

    #[Test]
    public function missing_ambiguous_or_forbidden_norm_is_a_blocker_and_never_a_fake_total(): void
    {
        foreach ([
            ['normative_match' => null, 'normative_retrieval' => ['status' => 'unavailable', 'blocking_issues' => ['normative_not_found']]],
            ['normative_retrieval' => ['status' => 'review_required', 'blocking_issues' => ['normative_ambiguous']]],
            ['normative_match' => [...$this->validWorkItem()['normative_match'], 'hard_gate_passed' => false, 'selection_source' => 'ai_reranker']],
        ] as $changes) {
            $item = [...$this->validWorkItem(), ...$changes];
            $draft = $this->builder()->build($this->draft([$item]));

            self::assertSame('review_required', $draft['stage6_status']);
            self::assertFalse($draft['is_complete']);
            self::assertSame('normative_blocking', $draft['stage6_review_items'][0]['type']);
            self::assertSame('0', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['total_cost']);
            self::assertSame('not_calculated', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['pricing_status']);
        }
    }

    #[Test]
    public function missing_stale_wrong_region_or_wrong_unit_price_is_blocking(): void
    {
        $cases = [
            ['price_snapshot' => null],
            ['price_snapshot' => [...$this->validWorkItem()['price_snapshot'], 'final_amount' => '0']],
            ['price_snapshot' => [...$this->validWorkItem()['price_snapshot'], 'version_id' => 8]],
            ['price_snapshot' => [...$this->validWorkItem()['price_snapshot'], 'region_id' => 88]],
            ['pricing_status' => 'not_calculated', 'pricing_blocker' => 'unit_mismatch'],
        ];
        foreach ($cases as $changes) {
            $draft = $this->builder()->build($this->draft([[...$this->validWorkItem(), ...$changes]]));
            self::assertSame('price_blocking', $draft['stage6_review_items'][0]['type']);
            self::assertSame('0', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['total_cost']);
        }
    }

    #[Test]
    public function incomplete_or_stale_quantity_is_blocking_and_persistence_total_is_exact_decimal_string(): void
    {
        $item = $this->validWorkItem();
        $item['quantity_evidence']['review_blockers'] = ['operand_not_confirmed'];
        $draft = $this->builder()->build($this->draft([$item]));
        self::assertSame('quantity_blocking', $draft['stage6_review_items'][0]['type']);

        $ready = $this->builder()->build($this->draft([$this->validWorkItem()]));
        self::assertSame('1250', $this->persistence()->persistableDraftTotal($ready));
        self::assertSame('incomplete_stage6_draft', $this->persistence()->findApplyBlocker($draft)['type'] ?? null);
    }

    #[Test]
    public function row_and_metadata_budgets_fail_closed_and_remove_unbounded_source_content(): void
    {
        $item = $this->validWorkItem();
        $item['prompt'] = str_repeat('secret', 10000);
        $item['raw_document'] = str_repeat('document', 10000);
        $draft = $this->draft(array_fill(0, 6, $item));
        $draft['stage6_limits'] = ['max_rows' => 5, 'max_metadata_bytes_per_row' => 32768, 'max_source_refs_per_row' => 16];

        $result = $this->builder()->build($draft);

        self::assertSame('draft_row_budget_exceeded', $result['stage6_review_items'][0]['code']);
        self::assertCount(5, $result['local_estimates'][0]['sections'][0]['work_items']);
        $metadata = $result['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['stage6_provenance'];
        self::assertStringNotContainsString('secret', json_encode($metadata, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('documentdocument', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    private function builder(): BuildMostEstimateDraft
    {
        return new BuildMostEstimateDraft(static fn (string $key): string => 'msg:'.$key);
    }

    private function persistence(): EstimateDraftPersistenceService
    {
        return new EstimateDraftPersistenceService(
            new EstimateGenerationFinalWorkItemGuard,
            new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter),
        );
    }

    /** @param list<array<string, mixed>> $workItems @return array<string, mixed> */
    private function draft(array $workItems): array
    {
        return [
            'title' => 'Черновик',
            'source_input_version' => 'sha256:'.str_repeat('f', 64),
            'input_snapshot_hash' => str_repeat('a', 64),
            'scope_identity' => [
                'organization_id' => 10,
                'project_id' => 20,
                'session_id' => 30,
                'source_version' => 'sha256:'.str_repeat('f', 64),
            ],
            'catalog_identity' => ['version' => 'fsnb:v1', 'hash' => str_repeat('b', 64), 'status' => 'current'],
            'technology_identity' => ['version' => 'technology:v1', 'hash' => str_repeat('c', 64), 'status' => 'current'],
            'rule_identity' => ['version' => 'rules:v1', 'hash' => str_repeat('d', 64), 'status' => 'current'],
            'regional_context' => [
                'region_id' => 77,
                'price_zone_id' => 4,
                'period_id' => 202601,
                'estimate_regional_price_version_id' => 9,
            ],
            'local_estimates' => [[
                'key' => 'local:1',
                'title' => 'Локальная смета',
                'sections' => [[
                    'key' => 'section:1',
                    'title' => 'Раздел',
                    'work_items' => $workItems,
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function validWorkItem(): array
    {
        return [
            'key' => 'work:floor',
            'name' => 'Устройство пола',
            'item_type' => 'priced_work',
            'quantity' => '12.5',
            'unit' => 'm2',
            'quantity_evidence' => [
                'amount' => '12.5',
                'unit' => 'm2',
                'formula_key' => 'floor_area',
                'formula_identity' => 'floor_area',
                'formula_version' => '1',
                'rounding_policy' => ['mode' => 'half_up', 'scale' => 2, 'boundary' => 'formula_result'],
                'formula_inputs' => ['operands' => [[
                    'fact_id' => 'fact:length',
                    'version' => 1,
                    'value' => '5',
                    'unit' => 'm',
                    'organization_id' => 10,
                    'project_id' => 20,
                    'session_id' => 30,
                    'source_version' => 'sha256:'.str_repeat('f', 64),
                    'evidence_ids' => ['evidence:1'],
                ]]],
                'evidence_ids' => ['evidence:1'],
                'review_blockers' => [],
                'model_version' => 'sha256:'.str_repeat('f', 64),
                'snapshot_identity' => ['input_fingerprint' => str_repeat('a', 64)],
            ],
            'source_refs' => [['artifact_id' => 'artifact:1', 'page' => 1, 'native_reference' => 'cad:line:1']],
            'normative_match' => [
                'status' => 'matched',
                'hard_gate_passed' => true,
                'norm_id' => 7,
                'code' => 'FSNB-01-001',
                'name' => 'Работа',
                'unit' => 'm2',
                'dataset_version' => ['source_type' => 'fsnb_2022', 'version_key' => 'fsnb:v1'],
                'resources_count' => 1,
                'priced_resources_count' => 1,
                'decision' => ['status' => 'accepted'],
            ],
            'normative_retrieval' => ['status' => 'matched', 'blocking_issues' => []],
            'price_snapshot' => [
                'region_id' => 77,
                'zone_id' => 4,
                'period_id' => 202601,
                'version_id' => 9,
                'source_type' => 'regional_catalog',
                'source_reference' => 'regional:price:1',
                'base_amount' => '100',
                'coefficients' => ['quantity' => '12.5'],
                'final_amount' => '1250',
                'currency' => 'RUB',
                'captured_at' => '2026-08-11T00:00:00+03:00',
            ],
            'price_source' => 'regional_catalog',
            'pricing_status' => 'calculated',
            'pricing_blocker' => null,
            'pricing_finalized_at' => '2026-08-11T00:00:00+03:00',
            'total_cost' => '1250',
            'materials' => [[
                'name' => 'Материал по нормативу',
                'quantity' => '1',
                'unit' => 'm2',
                'unit_price' => '1250',
                'total_price' => '1250',
            ]],
            'metadata' => [
                'technology_decision' => ['id' => 'decision:technology:1', 'version' => 1, 'status' => 'current'],
                'completeness_decision' => ['id' => 'decision:completeness:1', 'version' => 1, 'status' => 'current'],
            ],
        ];
    }
}
