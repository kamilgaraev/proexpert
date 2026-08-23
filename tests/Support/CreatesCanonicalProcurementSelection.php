<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierPartyService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleOwnerEventRecorder;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use DateTimeImmutable;

trait CreatesCanonicalProcurementSelection
{
    protected function createCanonicalSelectionSupplierRequest(
        Organization $organization,
        ?float $budgetAmount,
        string $purchaseRequestNumber,
        string $supplierRequestNumber
    ): SupplierRequest {
        $actor = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'title' => $purchaseRequestNumber,
            'status' => 'approved',
            'priority' => 'medium',
            'request_type' => 'material_request',
            'material_name' => 'Test material',
            'material_quantity' => 1,
            'material_unit' => 'pcs',
        ]);
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'site_request_id' => $siteRequest->id,
            'request_number' => $purchaseRequestNumber,
            'status' => 'approved',
            'budget_amount' => $budgetAmount,
            'budget_currency' => 'RUB',
        ]);
        $purchaseRequestLine = $purchaseRequest->lines()->create([
            'name' => 'Test material',
            'quantity' => 1,
            'unit' => 'pcs',
        ]);
        app(ProcurementCycleOwnerEventRecorder::class)->recordRequestCreated(
            $purchaseRequest,
            $actor->id,
            new DateTimeImmutable()
        );
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "{$supplierRequestNumber} supplier",
            'tax_number' => '7701000000',
            'is_active' => true,
        ]);
        $supplierParty = app(SupplierPartyService::class)->resolveRegisteredParty(
            $organization->id,
            $supplier->id
        );
        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'supplier_id' => $supplier->id,
            'supplier_party_id' => $supplierParty->id,
            'supplier_snapshot' => app(SupplierPartyService::class)->snapshotForDocument($supplierParty),
            'request_number' => $supplierRequestNumber,
            'status' => 'responded',
            'sent_at' => now(),
        ]);
        $supplierRequest->lines()->create([
            'purchase_request_line_id' => $purchaseRequestLine->id,
            'name' => $purchaseRequestLine->name,
            'quantity' => $purchaseRequestLine->quantity,
            'unit' => $purchaseRequestLine->unit,
        ]);

        app(SupplierRequestVersionService::class)->createSentVersion($supplierRequest, $actor->id);

        return $supplierRequest->refresh();
    }

    protected function createCanonicalSelectionProposal(
        Organization $organization,
        SupplierRequest $supplierRequest,
        string $proposalNumber,
        float $totalAmount,
        ?array $supplierSnapshot = null,
        ?float $subtotalAmount = null,
        float $deliveryAmount = 0,
        float $vatAmount = 0,
        string $status = 'submitted'
    ): SupplierProposal {
        if ((int) $supplierRequest->organization_id === (int) $organization->id) {
            $supplier = Supplier::query()->findOrFail((int) $supplierRequest->supplier_id);
            $supplierParty = app(SupplierPartyService::class)->resolveRegisteredParty(
                $organization->id,
                $supplier->id
            );
        } else {
            $supplier = Supplier::query()->create([
                'organization_id' => $organization->id,
                'name' => "{$proposalNumber} supplier",
                'tax_number' => $supplierSnapshot['tax_id'] ?? '7701000000',
                'is_active' => true,
            ]);
            $supplierParty = app(SupplierPartyService::class)->resolveRegisteredParty(
                $organization->id,
                $supplier->id
            );
        }
        $requestVersion = app(SupplierRequestVersionService::class)->currentSentVersion($supplierRequest);

        if ($requestVersion === null) {
            $requestVersion = app(SupplierRequestVersionService::class)->createSentVersion($supplierRequest);
        }

        $proposal = SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'supplier_request_version_id' => $requestVersion->id,
            'supplier_id' => $supplier->id,
            'supplier_party_id' => $supplierParty->id,
            'supplier_snapshot' => $supplierSnapshot ?? app(SupplierPartyService::class)
                ->snapshotForDocument($supplierParty),
            'proposal_number' => $proposalNumber,
            'proposal_date' => now()->toDateString(),
            'status' => $status,
            'subtotal_amount' => $subtotalAmount ?? $totalAmount,
            'delivery_amount' => $deliveryAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
            'vat_mode' => 'included',
            'vat_rate' => 20,
            'delivery_due_date' => now()->addDays(5)->toDateString(),
            'lead_time_days' => 5,
            'delivery_terms' => 'Delivery to project warehouse',
            'payment_terms' => 'Payment after acceptance',
        ]);
        $requestLine = $supplierRequest->lines()->firstOrFail();
        $lineAmount = $totalAmount > 0 ? $totalAmount : (float) ($subtotalAmount ?? 0);

        $proposal->lines()->create([
            'supplier_request_line_id' => $requestLine->id,
            'name' => $requestLine->name,
            'quantity' => $requestLine->quantity,
            'unit' => $requestLine->unit,
            'unit_price' => $lineAmount,
            'total_amount' => $lineAmount,
        ]);

        app(SupplierProposalVersionService::class)->createInitialVersion($proposal);

        return $proposal->refresh();
    }
}
