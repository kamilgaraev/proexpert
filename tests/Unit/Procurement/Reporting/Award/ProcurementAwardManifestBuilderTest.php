<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardManifestBuilder;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardManifestBuilderTest extends TestCase
{
    public function test_complete_same_currency_manifest_has_stable_order_and_cheapest_exact_version(): void
    {
        $manifest = (new ProcurementAwardManifestBuilder)->build([
            $this->candidate(20, 201, '125.00'),
            $this->candidate(10, 101, '100.00'),
        ], 20);

        self::assertSame([10, 20], array_map(
            static fn ($candidate): int => $candidate->proposalId,
            $manifest->candidates,
        ));
        self::assertSame(ProcurementAwardCompleteness::COMPLETE, $manifest->completeness);
        self::assertSame(2, $manifest->candidateCount);
        self::assertSame(2, $manifest->comparableCount);
        self::assertSame(10, $manifest->cheapestProposalId);
        self::assertSame(101, $manifest->cheapestProposalVersionId);
        self::assertSame(2, $manifest->selectedRank);
        self::assertSame(1, $manifest->cheapestRank);
    }

    public function test_positive_total_wins_over_component_sum_and_zero_total_uses_components(): void
    {
        $positive = $this->candidate(10, 101, '120.00');
        $positive['commercial_snapshot']['subtotal_amount'] = '1.00';
        $positive['commercial_snapshot']['delivery_amount'] = '1.00';
        $positive['commercial_snapshot']['vat_amount'] = '1.00';

        $fallback = $this->candidate(20, 201, '0.00');
        $fallback['commercial_snapshot']['subtotal_amount'] = '90.00';
        $fallback['commercial_snapshot']['delivery_amount'] = '5.00';
        $fallback['commercial_snapshot']['vat_amount'] = '5.00';

        $manifest = (new ProcurementAwardManifestBuilder)->build([$positive, $fallback], 10);

        self::assertSame('120', $manifest->candidates[0]->comparisonTotal);
        self::assertSame('100', $manifest->candidates[1]->comparisonTotal);
        self::assertSame(20, $manifest->cheapestProposalId);
    }

    public function test_cross_currency_set_is_preserved_but_not_computable_without_fx_policy(): void
    {
        $usd = $this->candidate(20, 201, '1.00');
        $usd['commercial_snapshot']['currency'] = 'USD';

        $manifest = (new ProcurementAwardManifestBuilder)->build([
            $this->candidate(10, 101, '100.00'),
            $usd,
        ], 10);

        self::assertSame(ProcurementAwardCompleteness::NOT_COMPARABLE, $manifest->completeness);
        self::assertSame(0, $manifest->comparableCount);
        self::assertNull($manifest->cheapestProposalId);
        foreach ($manifest->candidates as $candidate) {
            self::assertContains('currency_mismatch', $candidate->exclusionCodes);
        }
    }

    public function test_missing_exact_version_hash_is_quarantined_without_current_row_reconstruction(): void
    {
        $legacy = $this->candidate(10, 101, '100.00');
        $legacy['version_content_hash'] = null;

        $manifest = (new ProcurementAwardManifestBuilder)->build([$legacy], 10);

        self::assertSame(ProcurementAwardCompleteness::LEGACY_UNVERIFIED, $manifest->completeness);
        self::assertNull($manifest->cheapestProposalId);
        self::assertContains('legacy_unverified_proposal_version', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_legacy_numeric_snapshots_are_quarantined_without_fake_amounts_or_workflow_failure(): void
    {
        $legacy = $this->candidate(10, 101, '100.00');
        $legacy['supplier_request_version_hash'] = null;
        $legacy['version_content_hash'] = null;
        $legacy['request_lines'][0]['quantity'] = 2.0;
        $legacy['commercial_snapshot']['subtotal_amount'] = 100.0;
        $legacy['commercial_snapshot']['lines'][0]['quantity'] = 2.0;

        $manifest = (new ProcurementAwardManifestBuilder)->build([$legacy], 10);

        self::assertSame(ProcurementAwardCompleteness::LEGACY_UNVERIFIED, $manifest->completeness);
        self::assertNull($manifest->candidates[0]->subtotalAmount);
        self::assertNull($manifest->candidates[0]->requestLineCoverage[0]['required_quantity']);
        self::assertContains('invalid_request_line_quantity', $manifest->candidates[0]->exclusionCodes);
        self::assertContains('invalid_proposal_line_quantity', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_missing_version_and_expired_status_remain_visible_in_manifest_as_explicit_gaps(): void
    {
        $excluded = $this->candidate(10, 101, '100.00');
        $excluded['proposal_version_id'] = null;
        $excluded['version_content_hash'] = null;
        $excluded['proposal_status'] = 'rejected';
        $excluded['proposal_valid_until'] = '2026-07-31';

        $manifest = (new ProcurementAwardManifestBuilder)->build([$excluded], 10);

        self::assertSame(1, $manifest->candidateCount);
        self::assertNull($manifest->selectedProposalVersionId);
        self::assertNull($manifest->cheapestProposalId);
        self::assertContains('missing_proposal_version', $manifest->candidates[0]->exclusionCodes);
        self::assertContains('proposal_status_not_comparable', $manifest->candidates[0]->exclusionCodes);
        self::assertContains('expired_proposal', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_duplicate_proposal_line_identity_is_never_ranked(): void
    {
        $duplicate = $this->candidate(10, 101, '100.00');
        $duplicate['commercial_snapshot']['lines'][] = $duplicate['commercial_snapshot']['lines'][0];

        $manifest = (new ProcurementAwardManifestBuilder)->build([$duplicate], 10);

        self::assertNull($manifest->cheapestProposalId);
        self::assertContains('duplicate_proposal_request_line', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_incomplete_request_line_coverage_is_not_ranked(): void
    {
        $partial = $this->candidate(10, 101, '50.00');
        array_pop($partial['commercial_snapshot']['lines']);

        $manifest = (new ProcurementAwardManifestBuilder)->build([$partial], 10);

        self::assertSame(ProcurementAwardCompleteness::NOT_COMPARABLE, $manifest->completeness);
        self::assertNull($manifest->cheapestProposalId);
        self::assertContains('incomplete_request_line_coverage', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_missing_project_lineage_is_explicit_gap_and_never_ranked(): void
    {
        $unassigned = $this->candidate(10, 101, '50.00');
        $unassigned['project_id'] = null;

        $manifest = (new ProcurementAwardManifestBuilder)->build([$unassigned], 10);

        self::assertSame(ProcurementAwardCompleteness::GAP, $manifest->completeness);
        self::assertNull($manifest->cheapestProposalId);
        self::assertContains('missing_project_lineage', $manifest->candidates[0]->exclusionCodes);
    }

    public function test_manifest_rejects_unproved_purchase_request_round(): void
    {
        $foreignRound = $this->candidate(20, 201, '90.00');
        $foreignRound['purchase_request_id'] = 501;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('procurement_award_purchase_request_round_not_supported');

        (new ProcurementAwardManifestBuilder)->build([
            $this->candidate(10, 101, '100.00'),
            $foreignRound,
        ], 10);
    }

    public function test_candidate_limit_fails_closed_instead_of_partial_ranking(): void
    {
        $candidates = [];
        for ($id = 1; $id <= 101; $id++) {
            $candidates[] = $this->candidate($id, 1000 + $id, (string) $id);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('procurement_award_candidate_limit_exceeded');

        (new ProcurementAwardManifestBuilder)->build($candidates, 1);
    }

    private function candidate(int $proposalId, int $versionId, string $total): array
    {
        return [
            'organization_id' => 1,
            'project_id' => 2,
            'purchase_request_id' => 3,
            'supplier_request_id' => 4,
            'supplier_request_version_id' => 5,
            'supplier_request_version_hash' => str_repeat('b', 64),
            'proposal_id' => $proposalId,
            'proposal_version_id' => $versionId,
            'supplier_party_id' => 300 + $proposalId,
            'proposal_status' => 'submitted',
            'proposal_valid_until' => '2026-08-31',
            'selection_date' => '2026-08-01',
            'version_content_hash' => str_repeat('a', 64),
            'request_lines' => [
                ['id' => 1, 'quantity' => '2.000', 'unit' => 'kg'],
                ['id' => 2, 'quantity' => '1.000', 'unit' => 'pcs'],
            ],
            'commercial_snapshot' => [
                'subtotal_amount' => $total,
                'delivery_amount' => '0.00',
                'vat_amount' => '0.00',
                'total_amount' => $total,
                'currency' => 'RUB',
                'vat_mode' => 'included',
                'vat_rate' => '20.00',
                'delivery_due_date' => '2026-08-10',
                'lead_time_days' => 5,
                'lines' => [
                    [
                        'supplier_request_line_id' => 1,
                        'quantity' => '2.000',
                        'unit' => 'kg',
                        'unit_price' => '40.00',
                        'total_amount' => '80.00',
                    ],
                    [
                        'supplier_request_line_id' => 2,
                        'quantity' => '1.000',
                        'unit' => 'pcs',
                        'unit_price' => '20.00',
                        'total_amount' => '20.00',
                    ],
                ],
            ],
        ];
    }
}
