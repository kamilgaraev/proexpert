<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessInspector;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionReadinessGateTest extends TestCase
{
    #[Test]
    #[DataProvider('blockingDrafts')]
    public function every_production_blocker_prevents_apply(string $code, array $override): void
    {
        $draft = array_replace_recursive($this->readyDraft(), $override);
        $inspection = (new DraftReadinessInspector)->inspect($draft);

        self::assertContains($code, array_column($inspection->blockingIssues, 'code'));

        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));
        self::assertFalse($result->canApply);
        self::assertContains($code, array_column($result->blockingIssues, 'code'));
        self::assertSame('review_draft', $result->nextAction['code']);
    }

    public static function blockingDrafts(): iterable
    {
        yield 'scale missing' => ['geometry_scale_missing', ['building_model' => ['scale_status' => 'unknown']]];
        yield 'scale conflict' => ['geometry_scale_conflict', ['building_model' => ['scale_status' => 'conflict']]];
        yield 'scale unconfirmed' => ['geometry_scale_unconfirmed', ['building_model' => ['scale_status' => 'estimated']]];
        yield 'evidence missing' => ['evidence_missing', ['building_model' => ['evidence_ids' => null]]];
        yield 'evidence invalid' => ['evidence_invalid', ['building_model' => ['evidence_ids' => ['bad']]]];
        yield 'estimated quantity' => ['estimated_quantity_unconfirmed', self::itemOverride(['quantity_evidence' => [
            'source' => 'estimated', 'review_blockers' => ['estimated_quantity_unconfirmed'],
        ]])];
        yield 'normative missing' => ['normative_missing', self::itemOverride(['normative_match' => ['status' => 'not_found']])];
        yield 'normative rejected' => ['normative_rejected', self::itemOverride(['normative_match' => ['decision' => ['status' => 'rejected']]])];
        yield 'unit mismatch' => ['unit_mismatch', self::itemOverride(['normative_match' => ['warnings' => ['unit_mismatch']]])];
        yield 'price missing' => ['price_snapshot_missing', self::itemOverride(['price_snapshot' => null])];
        yield 'price unfinished' => ['price_snapshot_unfinalized', self::itemOverride(['pricing_finalized_at' => null])];
        yield 'duplicate' => ['duplicate_candidate', ['quality_summary' => ['duplicate_work_items' => 1]]];
        yield 'review' => ['blocking_review_unresolved', ['quality_summary' => ['review_items' => ['blocking' => 1]]]];
        yield 'model incomplete' => ['building_model_incomplete', ['building_model' => ['metrics' => ['complete' => false]]]];
        yield 'cad failure' => ['cad_processing_failed', ['building_model' => ['cad_status' => 'failed']]];
        yield 'required scope omitted' => ['required_scope_unresolved', [
            'package_plan' => ['packages' => [[
                'key' => 'openings',
                'title' => 'Окна и двери',
                'coverage_required' => true,
            ]]],
            'local_estimates' => [[
                'key' => 'openings',
                'sections' => [['work_items' => [[
                    'item_type' => 'review_note',
                ]]]],
            ]],
        ]];
    }

    private static function itemOverride(array $item): array
    {
        return ['local_estimates' => [0 => ['sections' => [0 => ['work_items' => [0 => $item]]]]]];
    }

    #[Test]
    public function fully_evidenced_priced_draft_is_ready_and_warnings_do_not_block(): void
    {
        $draft = $this->readyDraft();
        $draft['quality_summary']['warning_codes'] = ['low_confidence'];
        $inspection = (new DraftReadinessInspector)->inspect($draft);
        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));

        self::assertTrue($result->canGenerate);
        self::assertTrue($result->canApply);
        self::assertSame([], $result->blockingIssues);
        self::assertContains('low_confidence', array_column($result->warnings, 'code'));
        self::assertSame('apply_draft', $result->nextAction['code']);
        self::assertSame($result->toArray(), $result->toArray());
    }

    #[Test]
    public function source_backed_estimated_quantity_without_review_blockers_is_ready(): void
    {
        $draft = $this->readyDraft();
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_evidence'] = [
            'source' => 'estimated',
            'evidence_ids' => [1],
            'review_blockers' => [],
        ];
        $inspection = (new DraftReadinessInspector)->inspect($draft);
        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));

        self::assertNotContains('estimated_quantity_unconfirmed', array_column($inspection->blockingIssues, 'code'));
        self::assertTrue($result->canApply);
    }

    #[Test]
    public function unavailable_ai_composition_review_is_visible_but_deterministic_catalog_remains_usable(): void
    {
        $draft = $this->readyDraft();
        $draft['package_plan']['work_composition_advice'] = ['status' => 'unavailable'];

        $inspection = (new DraftReadinessInspector)->inspect($draft);
        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));

        self::assertContains('work_composition_ai_unavailable', array_column($inspection->warnings, 'code'));
        self::assertTrue($result->canApply);
    }

    #[Test]
    #[DataProvider('requiredQuantityCoverageOmissions')]
    public function unresolved_required_quantity_scope_blocks_apply(
        string $packageKey,
        string $quantityKey,
        string $reason,
    ): void {
        $draft = $this->readyDraft();
        $draft['local_estimates'][0]['key'] = $packageKey;
        $draft['local_estimates'][0]['coverage_warnings'] = [[
            'quantity_key' => $quantityKey,
            'reason' => $reason,
            'package_key' => $packageKey,
        ]];

        $inspection = (new DraftReadinessInspector)->inspect($draft);
        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));
        $issue = array_values(array_filter(
            $inspection->blockingIssues,
            static fn (array $issue): bool => ($issue['code'] ?? null) === 'required_scope_unresolved',
        ))[0] ?? null;

        self::assertSame($quantityKey, $issue['details']['quantity_coverage'][0]['quantity_key'] ?? null);
        self::assertSame($reason, $issue['details']['quantity_coverage'][0]['reason'] ?? null);
        self::assertFalse($result->canApply);
    }

    public static function requiredQuantityCoverageOmissions(): iterable
    {
        yield 'heating source' => ['heating', 'heating.unit', 'heating_source_type_missing'];
        yield 'sewer outlet' => ['sewerage', 'sewerage.outlets', 'sewer_outlet_route_missing'];
    }

    #[Test]
    #[DataProvider('advisoryQuantityCoverageOmissions')]
    public function explicit_external_or_not_applicable_scope_remains_visible_without_blocking(
        string $packageKey,
        string $quantityKey,
        string $reason,
    ): void {
        $draft = $this->readyDraft();
        $draft['local_estimates'][0]['key'] = $packageKey;
        $draft['local_estimates'][0]['coverage_warnings'] = [[
            'quantity_key' => $quantityKey,
            'reason' => $reason,
            'package_key' => $packageKey,
        ]];

        $inspection = (new DraftReadinessInspector)->inspect($draft);
        $result = (new EstimatorReadinessEvaluator)->evaluate($this->input($inspection->metrics));
        $warning = array_values(array_filter(
            $inspection->warnings,
            static fn (array $issue): bool => ($issue['code'] ?? null) === 'quantity_scope_omission',
        ))[0] ?? null;

        self::assertSame($quantityKey, $warning['details']['quantity_coverage'][0]['quantity_key'] ?? null);
        self::assertSame($reason, $warning['details']['quantity_coverage'][0]['reason'] ?? null);
        self::assertNotContains('required_scope_unresolved', array_column($inspection->blockingIssues, 'code'));
        self::assertTrue($result->canApply);
    }

    public static function advisoryQuantityCoverageOmissions(): iterable
    {
        yield 'external networks' => ['external_networks', 'networks.external', 'external_network_route_missing'];
        yield 'geodesy' => ['site', 'site.geodesy', 'site_geodetic_inputs_missing'];
        yield 'site setup' => ['site', 'site.setup', 'site_preparation_scope_missing'];
        yield 'not applicable' => ['electrical', 'electrical.trays', 'not_applicable_to_residential_preliminary_scenario'];
    }

    private function input(array $draftMetrics): EstimatorReadinessInput
    {
        return new EstimatorReadinessInput('ready_to_apply', true, 'passed', 'passed', array_merge([
            'documents_total' => 1, 'documents_ready' => 1, 'priced_work_items_total' => 1,
        ], $draftMetrics));
    }

    private function readyDraft(): array
    {
        return [
            'building_model' => [
                'scale_status' => 'confirmed', 'evidence_ids' => [1],
                'metrics' => ['complete' => true], 'cad_status' => 'completed',
            ],
            'quality_summary' => ['duplicate_work_items' => 0, 'review_items' => ['blocking' => 0], 'warning_codes' => []],
            'local_estimates' => [['sections' => [['work_items' => [[
                'item_type' => 'priced_work', 'quantity' => '10',
                'quantity_evidence' => ['source' => 'derived', 'evidence_ids' => [1]],
                'normative_match' => ['status' => 'matched', 'decision' => ['status' => 'accepted'], 'warnings' => []],
                'price_snapshot' => ['version_id' => 1], 'pricing_finalized_at' => '2026-07-12T00:00:00Z',
            ]]]]]],
        ];
    }
}
