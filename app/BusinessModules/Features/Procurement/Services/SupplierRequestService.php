<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierRequestService
{
    public function __construct(
        private readonly SupplierPartyService $supplierPartyService,
        private readonly ProcurementAuditService $auditService,
        private readonly SupplierRequestVersionService $versionService
    ) {
    }

    public function create(int $organizationId, array $data, ?int $actorId = null): SupplierRequest
    {
        return DB::transaction(function () use ($organizationId, $data, $actorId): SupplierRequest {
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
            $externalSupplierContact = $this->resolveExternalSupplierContact($organizationId, $data);
            $externalSupplierContactId = $externalSupplierContact?->id;

            if (($supplierId === null) === ($externalSupplierContactId === null)) {
                throw ValidationException::withMessages([
                    'supplier' => trans_message('procurement.supplier_requests.single_supplier_source_required'),
                ]);
            }

            if ($supplierId !== null) {
                $supplierParty = $this->supplierPartyService->resolveRegisteredParty($organizationId, (int) $supplierId);
            } else {
                if (!$externalSupplierContact instanceof ExternalSupplierContact) {
                    throw ValidationException::withMessages([
                        'supplier' => trans_message('procurement.supplier_requests.single_supplier_source_required'),
                    ]);
                }

                $supplierParty = $this->supplierPartyService->resolveExternalParty(
                    $organizationId,
                    $externalSupplierContact
                );
            }

            $supplierSnapshot = $this->supplierPartyService->snapshotForDocument($supplierParty);

            $supplierRequest = SupplierRequest::query()->create([
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplierId,
                'external_supplier_contact_id' => $externalSupplierContactId,
                'supplier_party_id' => $supplierParty->id,
                'supplier_snapshot' => $supplierSnapshot,
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

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CREATED->value,
                $supplierRequest,
                $organizationId,
                $actorId,
                $supplierParty->id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'status' => $supplierRequest->status->value,
                    'purchase_request_number' => $purchaseRequest->request_number,
                    'supplier_name' => $this->supplierName($supplierSnapshot),
                    'supplier_snapshot' => $supplierSnapshot,
                    'lines_count' => $purchaseRequest->lines->count(),
                    'comment' => $supplierRequest->comment,
                ]
            );

            return $supplierRequest->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest']);
        });
    }

    public function send(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequest
    {
        if (!$supplierRequest->canBeSent()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_sent'),
            ]);
        }

        return DB::transaction(function () use ($supplierRequest, $actorId): SupplierRequest {
            $previousStatus = $supplierRequest->status->value;

            $supplierRequest->update([
                'status' => SupplierRequestStatusEnum::SENT,
                'sent_at' => now(),
            ]);

            $supplierRequest->loadMissing('purchaseRequest');
            $version = $this->versionService->createSentVersion($supplierRequest->refresh(), $actorId);
            $snapshot = is_array($supplierRequest->supplier_snapshot) ? $supplierRequest->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_SENT->value,
                $supplierRequest,
                (int) $supplierRequest->organization_id,
                $actorId,
                $supplierRequest->supplier_party_id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'previous_status' => $previousStatus,
                    'status' => SupplierRequestStatusEnum::SENT->value,
                    'sent_at' => $supplierRequest->sent_at?->toIso8601String(),
                    'supplier_request_version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
                    'supplier_name' => $this->supplierName($snapshot),
                ]
            );

            return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest', 'currentVersion']);
        });
    }

    public function cancel(SupplierRequest $supplierRequest, ?int $actorId = null): SupplierRequest
    {
        if (!$supplierRequest->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => trans_message('procurement.supplier_requests.cannot_be_cancelled'),
            ]);
        }

        return DB::transaction(function () use ($supplierRequest, $actorId): SupplierRequest {
            $previousStatus = $supplierRequest->status->value;

            $supplierRequest->update([
                'status' => SupplierRequestStatusEnum::CANCELLED,
                'cancelled_at' => now(),
            ]);

            $supplierRequest->loadMissing('purchaseRequest');
            $snapshot = is_array($supplierRequest->supplier_snapshot) ? $supplierRequest->supplier_snapshot : [];

            $this->auditService->record(
                ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CANCELLED->value,
                $supplierRequest,
                (int) $supplierRequest->organization_id,
                $actorId,
                $supplierRequest->supplier_party_id,
                [
                    'request_number' => $supplierRequest->request_number,
                    'previous_status' => $previousStatus,
                    'status' => SupplierRequestStatusEnum::CANCELLED->value,
                    'cancelled_at' => $supplierRequest->cancelled_at?->toIso8601String(),
                    'purchase_request_number' => $supplierRequest->purchaseRequest?->request_number,
                    'supplier_name' => $this->supplierName($snapshot),
                ]
            );

            return $supplierRequest->refresh()->load(['lines', 'supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest']);
        });
    }

    public function queryForOrganization(int $organizationId): Builder
    {
        return SupplierRequest::query()
            ->forOrganization($organizationId)
            ->with(['supplier', 'externalSupplierContact', 'supplierParty', 'purchaseRequest', 'currentVersion'])
            ->latest('id');
    }

    private function resolveExternalSupplierContact(int $organizationId, array $data): ?ExternalSupplierContact
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

        return $contact;
    }

    private function generateRequestNumber(): string
    {
        $prefix = 'ЗПС-' . now()->format('Ym');
        $lastNumber = SupplierRequest::query()
            ->where('request_number', 'like', $prefix . '-%')
            ->count() + 1;

        return sprintf('%s-%04d', $prefix, $lastNumber);
    }

    private function supplierName(array $supplierSnapshot): ?string
    {
        $name = $supplierSnapshot['display_name'] ?? null;

        return $name === null ? null : (string) $name;
    }
}
