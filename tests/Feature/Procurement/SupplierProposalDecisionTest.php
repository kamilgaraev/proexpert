<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\Models\Organization;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesCanonicalProcurementSelection;
use Tests\TestCase;

class SupplierProposalDecisionTest extends TestCase
{
    use CreatesCanonicalProcurementSelection;

    public function test_either_equal_minimum_can_win_without_a_non_lowest_reason(): void
    {
        foreach (['request', 'purchase'] as $scope) {
            $organization = Organization::factory()->create();
            $request = $this->createSupplierRequest($organization, 'PR-TIE-'.$scope, 'SR-TIE-'.$scope);
            $first = $this->createProposal($organization, $request, 'KP-TIE-FIRST-'.$scope, 100);
            $second = $this->createProposal($organization, $request, 'KP-TIE-SECOND-'.$scope, 100);
            $service = app(SupplierProposalComparisonService::class);
            $comparison = $scope === 'request'
                ? $service->comparisonForRequest($request)
                : $service->comparisonForPurchaseRequest($request->purchaseRequest);
            $this->assertSame([true, true], array_column($comparison['rows'], 'is_cheapest'));
            $this->assertSame($first->id, $comparison['cheapest_supplier_proposal_id']);
            $decision = $scope === 'request'
                ? $service->selectWinner($request, $second->id, null, null)
                : $service->selectWinnerForPurchaseRequest($request->purchaseRequest, $second->id, null, null);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->assertTrue($decision->is_lowest_price_selected);
            $this->assertNull($decision->decision_reason);
            $this->assertSame($second->id, $decision->winning_supplier_proposal_id);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }

    public function test_purchase_winner_must_match_the_sent_request_quantity(): void
    {
        $organization = Organization::factory()->create();
        $request = $this->createSupplierRequest($organization);
        $request->lines()->firstOrFail()->update(['quantity' => 0.5]);
        app(\App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService::class)
            ->createSentVersion($request->fresh());
        $proposal = $this->createProposal($organization, $request->fresh(), 'KP-WRONG-SENT-QUANTITY', 100);
        $versions = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versions->commercialSnapshot($proposal->fresh(['lines']));
        $snapshot['lines'][0]['quantity'] = '1';
        $proposal->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versions->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);
        $service = app(SupplierProposalComparisonService::class);
        $comparison = $service->comparisonForPurchaseRequest($request->purchaseRequest);
        $this->assertFalse($comparison['rows'][0]['is_quantity_complete']);
        $this->assertFalse($comparison['rows'][0]['is_directly_comparable']);
        $this->assertNull($comparison['cheapest_supplier_proposal_id']);
        $this->expectException(ValidationException::class);
        $service->selectWinnerForPurchaseRequest($request->purchaseRequest, $proposal->id, null, null);
    }

    public function test_purchase_comparison_does_not_rank_different_request_conditions(): void
    {
        $organization = Organization::factory()->create();
        $request = $this->createSupplierRequest($organization);
        $first = $this->createProposal($organization, $request, 'KP-FIRST-SCOPE', 100);
        $request->lines()->firstOrFail()->update(['name' => 'Different specification']);
        app(\App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService::class)
            ->createSentVersion($request->fresh());
        $this->createProposal($organization, $request->fresh(), 'KP-OTHER-SCOPE', 90);

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($request->purchaseRequest);
        $this->assertSame([false, false], array_column($comparison['rows'], 'is_directly_comparable'));
        $this->assertNull($comparison['cheapest_supplier_proposal_id']);
        $prepared = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder::class)
            ->prepareForPurchaseRequest($request->purchaseRequest, $first->id, new \DateTimeImmutable);
        $this->assertSame(0, $prepared->manifest->comparableCount);
        $this->assertNull($prepared->manifest->cheapestProposalId);
    }

    public function test_saved_payable_ranking_keeps_tax_terms_and_unknown_delivery_separate(): void
    {
        $organization = Organization::factory()->create();
        $request = $this->createSupplierRequest($organization);
        $request->purchaseRequest->update(['needed_by' => now()->addDays(10)->toDateString()]);
        $cheapest = $this->createProposal($organization, $request, 'KP-UNKNOWN-TERM', 100);
        $selected = $this->createProposal($organization, $request, 'KP-KNOWN-TERM', 125);
        $versions = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versions->commercialSnapshot($cheapest->fresh(['lines']));
        $snapshot['vat_rate'] = '0';
        $snapshot['delivery_due_date'] = null;
        $snapshot['lead_time_days'] = null;
        $cheapest->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versions->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);
        $service = app(SupplierProposalComparisonService::class);
        $comparison = $service->comparisonForPurchaseRequest($request->purchaseRequest->fresh());
        $this->assertSame($cheapest->id, $comparison['cheapest_supplier_proposal_id']);
        $this->assertNotContains($cheapest->id, $comparison['lowest_on_time_supplier_proposal_ids']);
        $decision = $service->selectWinnerForPurchaseRequest($request->purchaseRequest, $selected->id, 'Согласован срок поставки', null);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $events = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore::class)
            ->eventsForDecision($decision->id);
        $manifest = $events[0]->manifest;
        $this->assertSame(2, $manifest->comparableCount);
        $this->assertSame($cheapest->id, $manifest->cheapestProposalId);
        $this->assertSame(2, $manifest->selectedRank);
        $evidence = collect($manifest->candidates)->firstWhere('proposalId', $cheapest->id);
        $this->assertSame('0', $evidence->vatRate);
        $this->assertNull($evidence->deliveryDueDate);
        $this->assertNull($evidence->leadTimeDays);
    }

    public function test_expired_foreign_offer_preserves_the_saved_current_offer_ranking(): void
    {
        $organization = Organization::factory()->create();
        $request = $this->createSupplierRequest($organization);
        $cheapest = $this->createProposal($organization, $request, 'KP-CURRENT-CHEAP', 100);
        $selected = $this->createProposal($organization, $request, 'KP-CURRENT-SELECTED', 125);
        $expired = $this->createProposal($organization, $request, 'KP-EXPIRED-USD', 1);
        $expired->update(['valid_until' => now()->subDay()->toDateString(), 'currency' => 'USD']);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versionService->commercialSnapshot($expired->fresh(['lines']));
        $snapshot['vat_rate'] = '0';
        $expired->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versionService->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);

        $service = app(SupplierProposalComparisonService::class);
        $comparison = $service->comparisonForPurchaseRequest($request->purchaseRequest);
        $this->assertSame([$cheapest->id, $selected->id], array_column($comparison['rows'], 'id'));
        $this->assertSame($cheapest->id, $comparison['cheapest_supplier_proposal_id']);

        $decision = $service->selectWinnerForPurchaseRequest(
            $request->purchaseRequest,
            $selected->id,
            'Выбраны подходящие условия поставки',
            null,
        );
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $events = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore::class)
            ->eventsForDecision($decision->id);
        $manifest = $events[0]->manifest;
        $this->assertSame(3, $manifest->candidateCount);
        $this->assertSame(2, $manifest->comparableCount);
        $this->assertSame($cheapest->id, $manifest->cheapestProposalId);
        $this->assertSame(2, $manifest->selectedRank);
        $expiredEvidence = collect($manifest->candidates)->firstWhere('proposalId', $expired->id);
        $this->assertFalse($expiredEvidence->comparable);
        $this->assertContains('expired_proposal', $expiredEvidence->exclusionCodes);
    }

    public function test_purchase_history_does_not_treat_a_fully_answered_partial_request_as_complete(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = $supplierRequest->purchaseRequest;
        $purchaseRequest->lines()->create(['name' => 'Additional material', 'quantity' => 2, 'unit' => 'pcs']);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-PARTIAL-REQUEST', 100);

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);
        $this->assertFalse($comparison['rows'][0]['is_quantity_complete']);
        $missing = collect($comparison['rows'][0]['line_coverage'])->firstWhere('covered_quantity', null);
        $this->assertSame('Additional material', $missing['name'] ?? null);
        $this->assertSame('2', $missing['required_quantity']);
        $prepared = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder::class)
            ->prepareForPurchaseRequest($purchaseRequest, $proposal->id, new \DateTimeImmutable);

        $this->assertSame(0, $prepared->manifest->comparableCount);
        $this->assertNull($prepared->manifest->cheapestProposalId);
        $this->assertContains('incomplete_purchase_line_coverage', $prepared->manifest->candidates[0]->exclusionCodes);
    }

    public function test_purchase_comparison_uses_an_earlier_line_deadline(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = $supplierRequest->purchaseRequest;
        $purchaseRequest->update(['needed_by' => '2026-09-15']);
        $purchaseRequest->lines()->firstOrFail()->update(['needed_by' => '2026-09-08']);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-EARLY-LINE', 100);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versionService->commercialSnapshot($proposal->fresh(['lines']));
        $snapshot['delivery_due_date'] = '2026-09-10';
        $proposal->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versionService->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest->fresh());

        $this->assertSame('2026-09-08', $comparison['needed_by']);
        $this->assertTrue($comparison['rows'][0]['delivery_assessment']['is_late']);
        $this->assertSame(2, $comparison['rows'][0]['delivery_assessment']['days_late']);
        $this->assertSame([], $comparison['lowest_on_time_supplier_proposal_ids']);
    }

    public function test_mixed_currencies_do_not_produce_a_global_cheapest_or_on_time_winner(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = $supplierRequest->purchaseRequest;
        $purchaseRequest->update(['needed_by' => '2026-09-15']);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);

        foreach ([['KP-RUB', 10000, 'RUB'], ['KP-USD', 150, 'USD']] as [$number, $total, $currency]) {
            $proposal = $this->createProposal($organization, $supplierRequest, $number, $total);
            $snapshot = $versionService->commercialSnapshot($proposal->fresh(['lines']));
            $snapshot['currency'] = $currency;
            $snapshot['delivery_due_date'] = '2026-09-10';
            $proposal->versions()->create([
                'organization_id' => $organization->id,
                'version_number' => 2,
                'commercial_snapshot' => $snapshot,
                'attachment_snapshot' => [],
                'content_hash' => $versionService->contentHash($snapshot),
                'integrity_status' => 'verified',
            ]);
        }

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);

        $this->assertNull($comparison['cheapest_supplier_proposal_id']);
        $this->assertSame([], $comparison['lowest_on_time_supplier_proposal_ids']);
        $this->assertSame([false, false], array_column($comparison['rows'], 'is_directly_comparable'));
        foreach ($comparison['rows'] as $row) {
            $this->assertNotEmpty($row['comparison_warnings']);
        }
    }

    public function test_on_time_price_ties_are_preserved_and_unknown_delivery_is_not_recommended(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = $supplierRequest->purchaseRequest;
        $purchaseRequest->update(['needed_by' => '2026-09-15']);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $ids = [];

        foreach ([['KP-UNKNOWN', 80, null], ['KP-BOUNDARY', 100, '2026-09-15'], ['KP-TIE', 100, '2026-09-14']] as [$number, $total, $date]) {
            $proposal = $this->createProposal($organization, $supplierRequest, $number, $total);
            $snapshot = $versionService->commercialSnapshot($proposal->fresh(['lines']));
            $snapshot['delivery_due_date'] = $date;
            $snapshot['lead_time_days'] = null;
            $proposal->versions()->create([
                'organization_id' => $organization->id,
                'version_number' => 2,
                'commercial_snapshot' => $snapshot,
                'attachment_snapshot' => [],
                'content_hash' => $versionService->contentHash($snapshot),
                'integrity_status' => 'verified',
            ]);
            $ids[] = $proposal->id;
        }

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);

        $this->assertSame($ids[0], $comparison['cheapest_supplier_proposal_id']);
        $this->assertSame([$ids[1], $ids[2]], $comparison['lowest_on_time_supplier_proposal_ids']);
        $purchaseRequest->update(['needed_by' => null]);
        $withoutDeadline = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);
        $this->assertSame([], $withoutDeadline['lowest_on_time_supplier_proposal_ids']);
    }

    public function test_five_offers_distinguish_lowest_price_from_full_delivery_on_time(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = $supplierRequest->purchaseRequest;
        $purchaseRequest->update(['needed_by' => '2026-09-15']);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $offers = [];

        foreach ([
            ['number' => 'KP-DELIVERY-COST', 'total' => 2000, 'delivery' => 1000, 'date' => '2026-09-10', 'quantity' => '1'],
            ['number' => 'KP-BALANCED', 'total' => 1700, 'date' => '2026-09-10', 'quantity' => '1'],
            ['number' => 'KP-FAST', 'total' => 1900, 'date' => '2026-09-06', 'quantity' => '1'],
            ['number' => 'KP-PARTIAL', 'total' => 640, 'date' => '2026-09-08', 'quantity' => '0.4'],
            ['number' => 'KP-LATE', 'total' => 1500, 'date' => '2026-09-20', 'quantity' => '1'],
        ] as $fixture) {
            if ($offers !== []) {
                $supplier = \App\Models\Supplier::query()->create([
                    'organization_id' => $organization->id,
                    'name' => $fixture['number'],
                    'tax_number' => '770100000'.count($offers),
                    'is_active' => true,
                ]);
                $parties = app(\App\BusinessModules\Features\Procurement\Services\SupplierPartyService::class);
                $party = $parties->resolveRegisteredParty($organization->id, $supplier->id);
                $supplierRequest = SupplierRequest::query()->create([
                    'organization_id' => $organization->id,
                    'purchase_request_id' => $purchaseRequest->id,
                    'supplier_id' => $supplier->id,
                    'supplier_party_id' => $party->id,
                    'supplier_snapshot' => $parties->snapshotForDocument($party),
                    'request_number' => 'SR-'.$fixture['number'],
                    'status' => 'responded',
                    'sent_at' => now(),
                ]);
                $requiredLine = $purchaseRequest->lines()->firstOrFail();
                $supplierRequest->lines()->create([
                    'purchase_request_line_id' => $requiredLine->id,
                    'name' => $requiredLine->name,
                    'quantity' => $requiredLine->quantity,
                    'unit' => $requiredLine->unit,
                ]);
                app(\App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService::class)
                    ->createSentVersion($supplierRequest);
            }
            $proposal = $this->createProposal($organization, $supplierRequest, $fixture['number'], $fixture['total']);
            $snapshot = $versionService->commercialSnapshot($proposal->fresh(['lines']));
            $delivery = $fixture['delivery'] ?? 0;
            $snapshot['subtotal_amount'] = (string) ($fixture['total'] - $delivery);
            $snapshot['delivery_amount'] = (string) $delivery;
            $snapshot['delivery_due_date'] = $fixture['date'];
            $snapshot['lines'][0]['quantity'] = $fixture['quantity'];
            $snapshot['lines'][0]['unit_price'] = (string) (($fixture['total'] - $delivery) / (float) $fixture['quantity']);
            $snapshot['lines'][0]['total_amount'] = $snapshot['subtotal_amount'];
            $proposal->versions()->create([
                'organization_id' => $organization->id,
                'version_number' => 2,
                'commercial_snapshot' => $snapshot,
                'attachment_snapshot' => [],
                'content_hash' => $versionService->contentHash($snapshot),
                'integrity_status' => 'verified',
            ]);
            $offers[$fixture['number']] = $proposal->id;
        }

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);

        $this->assertCount(5, $comparison['rows']);
        $this->assertSame(5, $purchaseRequest->supplierRequests()->distinct()->count('supplier_party_id'));
        $this->assertSame(5, $purchaseRequest->supplierRequests()->count());
        $this->assertSame($offers['KP-LATE'], $comparison['cheapest_supplier_proposal_id']);
        $this->assertSame([$offers['KP-BALANCED']], $comparison['lowest_on_time_supplier_proposal_ids'] ?? []);
        $deliveryRow = collect($comparison['rows'])->firstWhere('id', $offers['KP-DELIVERY-COST']);
        $this->assertSame(2000.0, $deliveryRow['comparison_total']);
        $this->assertSame(1000.0, $deliveryRow['delivery_amount']);
        $prepared = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder::class)
            ->prepareForPurchaseRequest($purchaseRequest, $offers['KP-BALANCED'], new \DateTimeImmutable);
        $this->assertSame(5, $prepared->manifest->candidateCount);
        $this->assertSame(4, $prepared->manifest->comparableCount);
        $partial = collect($prepared->manifest->candidates)->firstWhere('proposalId', $offers['KP-PARTIAL']);
        $this->assertFalse($partial->comparable);
        $this->assertContains('incomplete_request_line_coverage', $partial->exclusionCodes);
        $decision = app(SupplierProposalComparisonService::class)->selectWinnerForPurchaseRequest(
            $purchaseRequest,
            $offers['KP-BALANCED'],
            'Поставка полного объёма в требуемый срок',
            null,
        );
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $events = app(\App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore::class)
            ->eventsForDecision($decision->id);
        $recorded = $events[0]->manifest;
        $this->assertSame('comparable_subset', $recorded->completeness->value);
        $this->assertSame(5, $recorded->candidateCount);
        $this->assertSame(4, $recorded->comparableCount);
        $this->assertSame($offers['KP-LATE'], $recorded->cheapestProposalId);
        $this->assertSame(2, $recorded->selectedRank);
        foreach ([
            ['selected_rank' => 1],
            ['cheapest_proposal_id' => $offers['KP-BALANCED'], 'cheapest_proposal_version_id' => $recorded->selectedProposalVersionId],
        ] as $invalidRank) {
            $forgedId = (string) \Illuminate\Support\Str::uuid();
            try {
                DB::transaction(function () use ($events, $invalidRank, $forgedId): void {
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');
                    $event = (array) DB::table('procurement_award_evidence_events')->where('id', $events[0]->eventId)->firstOrFail();
                    $event['id'] = $forgedId;
                    $event['event_sequence']++;
                    $event['decision_revision']++;
                    DB::table('procurement_award_evidence_events')->insert(array_replace($event, $invalidRank));
                    foreach (DB::table('procurement_award_evidence_candidates')->where('event_id', $events[0]->eventId)->get() as $candidate) {
                        $candidate = (array) $candidate;
                        $candidate['event_id'] = $forgedId;
                        DB::table('procurement_award_evidence_candidates')->insert($candidate);
                    }
                    DB::statement('SET CONSTRAINTS proc_award_subset_rank_event_check IMMEDIATE');
                    $this->fail('The database accepted an incorrect proposal rank.');
                });
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertStringContainsString('procurement award comparable subset rank mismatch', $exception->getMessage());
            }
            $this->assertFalse(DB::table('procurement_award_evidence_events')->where('id', $forgedId)->exists());
        }
    }

    public function test_comparison_uses_versioned_lines_and_reports_delivery_against_purchase_deadline(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $supplierRequest->purchaseRequest->update(['needed_by' => '2026-09-15']);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-VERSIONED', 100);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versionService->commercialSnapshot($proposal->fresh(['lines']));
        $snapshot['delivery_due_date'] = '2026-09-20';
        $proposal->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versionService->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);
        $proposal->lines()->firstOrFail()->update(['quantity' => 0.4]);

        $comparison = app(SupplierProposalComparisonService::class)
            ->comparisonForPurchaseRequest($supplierRequest->purchaseRequest->fresh());
        $row = collect($comparison['rows'])->firstWhere('id', $proposal->id);

        $this->assertSame(1.0, $row['lines'][0]['quantity']);
        $this->assertTrue($row['is_quantity_complete']);
        $this->assertSame('2026-09-15', $comparison['needed_by']);
        $this->assertSame('2026-09-20', $row['delivery_assessment']['expected_date']);
        $this->assertTrue($row['delivery_assessment']['is_late']);
        $this->assertSame(5, $row['delivery_assessment']['days_late']);
    }

    public function test_partial_offer_is_not_the_cheapest_for_the_full_purchase_requirement(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $complete = $this->createProposal($organization, $supplierRequest, 'KP-FULL', 1700);
        $partial = $this->createProposal($organization, $supplierRequest, 'KP-PARTIAL', 640);
        $partial->lines()->firstOrFail()->update([
            'quantity' => 0.4,
            'unit_price' => 1600,
            'total_amount' => 640,
        ]);
        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versionService->commercialSnapshot($partial->fresh(['lines']));
        $partial->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versionService->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);

        $comparison = app(SupplierProposalComparisonService::class)
            ->comparisonForPurchaseRequest($supplierRequest->purchaseRequest);

        $this->assertSame($complete->id, $comparison['cheapest_supplier_proposal_id']);
        $this->assertCount(2, $comparison['rows']);
        $partialRow = collect($comparison['rows'])->firstWhere('id', $partial->id);
        $this->assertFalse($partialRow['is_cheapest']);
        $this->assertNotEmpty($partialRow['comparison_warnings']);
    }

    public function test_comparison_lists_proposals_only_for_one_supplier_request(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $otherSupplierRequest = $this->createSupplierRequest($organization, 'PR-DEC-OTHER', 'SR-DEC-OTHER');

        $firstProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-001', 1200);
        $secondProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-002', 950);
        $foreignProposal = $this->createProposal($organization, $otherSupplierRequest, 'KP-DEC-003', 100);
        $foreignOrganization = Organization::factory()->create();
        $foreignOrganizationProposal = $this->createProposal(
            $foreignOrganization,
            $supplierRequest,
            'KP-DEC-FOREIGN-ORG',
            50
        );

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);

        $this->assertEqualsCanonicalizing(
            [$firstProposal->id, $secondProposal->id],
            array_column($comparison['rows'], 'id')
        );
        $this->assertNotContains($foreignProposal->id, array_column($comparison['rows'], 'id'));
        $this->assertNotContains($foreignOrganizationProposal->id, array_column($comparison['rows'], 'id'));
    }

    public function test_cheapest_proposal_uses_positive_total_and_falls_back_to_components(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);

        $componentProposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-DEC-004',
            totalAmount: 999,
            subtotalAmount: 80,
            deliveryAmount: 10,
            vatAmount: 10
        );

        $fallbackProposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-DEC-005',
            totalAmount: 0,
            subtotalAmount: 90,
            deliveryAmount: 0,
            vatAmount: 0
        );

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);

        $rows = collect($comparison['rows'])->keyBy('id');

        $this->assertSame($fallbackProposal->id, $comparison['cheapest_supplier_proposal_id']);
        $this->assertSame(999.0, $rows[$componentProposal->id]['comparison_total']);
        $this->assertSame(90.0, $rows[$fallbackProposal->id]['comparison_total']);
    }

    public function test_selecting_winner_automatically_creates_purchase_order_when_approval_is_not_required(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-006', 100);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $winner->id,
            null,
            null
        );

        $this->assertSame($supplierRequest->id, $decision->supplier_request_id);
        $this->assertSame($winner->id, $decision->winning_supplier_proposal_id);
        $this->assertSame($winner->id, $decision->cheapest_supplier_proposal_id);
        $this->assertTrue($decision->is_lowest_price_selected);
        $this->assertSame('selected', $decision->status->value);
        $this->assertDatabaseHas('supplier_proposal_decisions', [
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $winner->id,
            'status' => 'selected',
        ]);
        $this->assertSame('accepted', $winner->refresh()->status->value);
        $this->assertSame(1, PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $winner->id)
            ->where('purchase_request_id', $supplierRequest->purchase_request_id)
            ->count());
    }

    public function test_comparison_payload_includes_saved_decision(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = PurchaseRequest::query()->findOrFail($supplierRequest->purchase_request_id);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-006-A', 100);

        $decision = app(SupplierProposalComparisonService::class)->selectWinnerForPurchaseRequest(
            $purchaseRequest,
            $winner->id,
            null,
            null
        );

        $requestComparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);
        $purchaseRequestComparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);

        $this->assertSame($decision->id, $requestComparison['decision']['id']);
        $this->assertSame($winner->id, $requestComparison['decision']['winning_supplier_proposal_id']);
        $this->assertSame('selected', $requestComparison['decision']['status']);
        $this->assertSame($decision->id, $purchaseRequestComparison['decision']['id']);
        $this->assertSame($winner->id, $purchaseRequestComparison['decision']['winning_supplier_proposal_id']);
        $this->assertSame('selected', $purchaseRequestComparison['decision']['status']);
    }

    public function test_selecting_non_cheapest_requires_decision_reason(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $this->createProposal($organization, $supplierRequest, 'KP-DEC-007', 100);
        $expensiveProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-008', 150);

        $this->expectException(ValidationException::class);

        app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $expensiveProposal->id,
            '   ',
            null
        );
    }

    public function test_accept_proposal_requires_valid_decision_and_selected_proposal_matches(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        PurchaseOrder::query()->create([
            'organization_id' => $otherOrganization->id,
            'order_number' => 'ЗП-'.now()->format('Ym').'-0001',
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);

        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-009', 100);
        $otherProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-010', 150);

        try {
            app(SupplierProposalService::class)->accept($winner);
            $this->fail('Proposal without selected decision should not be accepted.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);

        try {
            app(SupplierProposalService::class)->accept($otherProposal);
            $this->fail('Proposal that was not selected should not be accepted.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $accepted = $winner->refresh();

        $this->assertSame('accepted', $accepted->status->value);
        $this->assertNotNull($accepted->purchase_order_id);
        $this->assertSame('ЗП-'.now()->format('Ym').'-0002', $accepted->purchaseOrder?->order_number);
    }

    public function test_cannot_change_winner_after_selected_proposal_accepted_and_order_created(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-011', 100);
        $otherProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-012', 150);

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);

        $this->assertSame('accepted', $winner->refresh()->status->value);

        $this->expectException(ValidationException::class);

        app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest->refresh(),
            $otherProposal->id,
            'Selected supplier cannot deliver on time.',
            null
        );
    }

    public function test_accept_proposal_locks_latest_version_without_aggregate_relation_query(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-LOCK', 100);

        $versionService = app(\App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService::class);
        $snapshot = $versionService->commercialSnapshot($winner->fresh(['lines']));
        $snapshot['subtotal_amount'] = '125';
        $snapshot['total_amount'] = '125';
        $snapshot['lines'][0]['unit_price'] = '125';
        $snapshot['lines'][0]['total_amount'] = '125';

        $winner->versions()->create([
            'organization_id' => $organization->id,
            'version_number' => 2,
            'commercial_snapshot' => $snapshot,
            'attachment_snapshot' => [],
            'content_hash' => $versionService->contentHash($snapshot),
            'integrity_status' => 'verified',
        ]);

        $versionQueries = [];

        DB::listen(static function ($query) use (&$versionQueries): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'supplier_proposal_versions')) {
                $versionQueries[] = $sql;
            }
        });

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);
        $accepted = $winner->refresh();

        $this->assertSame(2, $accepted->purchaseOrder?->acceptedSupplierProposalVersion?->version_number);
        $this->assertTrue(collect($versionQueries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'order by')
                && ! str_contains($sql, 'group by')
        ));
    }

    public function test_second_accept_with_stale_proposal_does_not_create_duplicate_order(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-013', 100);
        $staleWinner = SupplierProposal::query()->findOrFail($winner->id);

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);

        try {
            app(SupplierProposalService::class)->accept($staleWinner);
            $this->fail('Stale proposal accept should not create a duplicate purchase order.');
        } catch (DomainException|ValidationException) {
            $this->assertSame(1, PurchaseOrder::query()
                ->where('accepted_supplier_proposal_id', $winner->id)
                ->count());
        }
    }

    private function createSupplierRequest(
        Organization $organization,
        string $purchaseRequestNumber = 'PR-DEC-001',
        string $supplierRequestNumber = 'SR-DEC-001'
    ): SupplierRequest {
        return $this->createCanonicalSelectionSupplierRequest(
            $organization,
            null,
            $purchaseRequestNumber,
            $supplierRequestNumber
        );
    }

    private function createProposal(
        Organization $organization,
        SupplierRequest $supplierRequest,
        string $proposalNumber,
        float $totalAmount,
        ?float $subtotalAmount = null,
        float $deliveryAmount = 0,
        float $vatAmount = 0,
        string $status = 'submitted'
    ): SupplierProposal {
        return $this->createCanonicalSelectionProposal(
            $organization,
            $supplierRequest,
            $proposalNumber,
            $totalAmount,
            null,
            $subtotalAmount,
            $deliveryAmount,
            $vatAmount,
            $status
        );
    }
}
