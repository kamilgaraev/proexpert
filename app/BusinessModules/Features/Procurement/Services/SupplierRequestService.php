<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierRequestService
{
    public function create(int $organizationId, array $data): SupplierRequest
    {
        return DB::transaction(function () use ($organizationId, $data): SupplierRequest {
            $purchaseRequest = PurchaseRequest::query()
                ->forOrganization($organizationId)
                ->with('lines')
                ->findOrFail($data['purchase_request_id']);

            if ($purchaseRequest->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'purchase_request_id' => trans_message('procurement.supplier_requests.purchase_request_lines_required'),
                ]);
            }

            $supplierId = $data['supplier_id'] ?? null;
            $externalSupplierContactId = $this->resolveExternalSupplierContactId($organizationId, $data);

            if (($supplierId === null) === ($externalSupplierContactId === null)) {
                throw ValidationException::withMessages([
                    'supplier' => trans_message('procurement.supplier_requests.single_supplier_source_required'),
                ]);
            }

            if ($supplierId !== null) {
                Supplier::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->findOrFail($supplierId);
            }

            $supplierRequest = SupplierRequest::query()->create([
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplierId,
                'external_supplier_contact_id' => $externalSupplierContactId,
                'request_number' => $this->generateRequestNumber(),
                'status' => SupplierRequestStatusEnum::DRAFT,
                'comment' => $data['comment'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($purchaseRequest->lines as $line) {
                $supplierRequest->lines()->create([
                    'purchase_request_line_id' => $line->id,
                    'material_id' => $line->material_id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'specification' => $line->specification,
                    'metadata' => $line->metadata,
                ]);
            }

            return $supplierRequest->load(['lines', 'supplier', 'externalSupplierContact', 'purchaseRequest']);
        });
    }

    public function send(SupplierRequest $supplierRequest): SupplierRequest
    {
        if (!$supplierRequest->canBeSent()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_sent'),
            ]);
        }

        $supplierRequest->update([
            'status' => SupplierRequestStatusEnum::SENT,
            'sent_at' => now(),
        ]);

        return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'purchaseRequest']);
    }

    public function cancel(SupplierRequest $supplierRequest): SupplierRequest
    {
        if (!$supplierRequest->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_cancelled'),
            ]);
        }

        $supplierRequest->update([
            'status' => SupplierRequestStatusEnum::CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'purchaseRequest']);
    }

    public function queryForOrganization(int $organizationId): Builder
    {
        return SupplierRequest::query()
            ->forOrganization($organizationId)
            ->with(['supplier', 'externalSupplierContact', 'purchaseRequest'])
            ->latest('id');
    }

    private function resolveExternalSupplierContactId(int $organizationId, array $data): ?int
    {
        if (!isset($data['external_supplier'])) {
            return null;
        }

        $externalSupplier = $data['external_supplier'];
        $contact = ExternalSupplierContact::query()->create([
            'organization_id' => $organizationId,
            'name' => $externalSupplier['name'],
            'contact_person' => $externalSupplier['contact_person'] ?? null,
            'phone' => $externalSupplier['phone'] ?? null,
            'email' => $externalSupplier['email'] ?? null,
            'tax_number' => $externalSupplier['tax_number'] ?? null,
            'address' => $externalSupplier['address'] ?? null,
            'metadata' => $externalSupplier['metadata'] ?? null,
        ]);

        return $contact->id;
    }

    private function generateRequestNumber(): string
    {
        $prefix = 'ЗПС-' . now()->format('Ym');
        $lastNumber = SupplierRequest::query()
            ->where('request_number', 'like', $prefix . '-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }
}
